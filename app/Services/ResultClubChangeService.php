<?php

namespace App\Services;

use App\Models\Result;
use App\Models\SwimRecord;
use Illuminate\Support\Collection;
use Throwable;

/**
 * ResultClubChangeService
 *
 * Folgearbeiten, nachdem der Verein von Einzelergebnissen geändert wurde (z. B. per LENEX importierte Ergebnisse,
 * die unter dem Nationalteam ÖBSV statt unter dem Stammverein stehen) — einzeln (apply(), Bearbeiten-Formular) oder
 * gesammelt (reassign(), Seite "Vereine korrigieren" eines Wettkampfs):
 *
 *   - Rekorde aus dem Ergebnis bekommen den neuen Verein: die nationalen (AUT, AUT.JR) und die Landesrekorde des
 *     neuen Landesverbands. Landesrekorde eines ANDEREN Landesverbands bleiben unverändert und werden nur gemeldet —
 *     sie zu löschen würde in die Rekordkette eingreifen (Entscheidung Erik, 23.10.2026).
 *   - Ist der Wettkampf schon rekordgeprüft (es gibt Rekorde aus seinen Ergebnissen), wird das Ergebnis erneut
 *     geprüft, damit die Landesrekorde des neuen Vereins entstehen. Sonst nicht: Der Rekord-Check eines noch nicht
 *     geprüften Wettkampfs bleibt bewusst eine eigene Aktion (z. B. beim historischen Import in zeitlicher Reihenfolge).
 */
final readonly class ResultClubChangeService
{
    private const array NATIONAL_TYPES = ['AUT', 'AUT.JR'];

    public function __construct(
        private RecordCheckerService $checker,
    ) {}

    /**
     * Folgearbeiten für ein Ergebnis, dessen Verein bereits geändert und gespeichert ist.
     *
     * @return list<string> Hinweise für die Erfolgsmeldung
     *
     * @throws Throwable
     */
    public function apply(Result $result): array
    {
        return $this->notes([$this->followUp($result)]);
    }

    /**
     * Setzt den Verein mehrerer Ergebnisse ($clubFor liefert je Ergebnis die neue Vereins-ID) und erledigt die
     * Folgearbeiten. Nachgeprüft wird wie beim Rekord-Check der Veranstaltung nach Zeit sortiert (schnellste zuerst),
     * damit je Kategorie nur das beste Ergebnis den Rekord aufstellt.
     *
     * @param  Collection<int, Result>  $results
     * @param  callable(Result): int  $clubFor
     * @return array{changed: int, notes: list<string>}
     *
     * @throws Throwable
     */
    public function reassign(Collection $results, callable $clubFor): array
    {
        $changed = $results->filter(function (Result $result) use ($clubFor): bool {
            $clubId = $clubFor($result);
            if ($clubId === $result->club_id) {
                return false;
            }
            $result->update(['club_id' => $clubId]);

            return true;
        });

        $outcomes = $changed
            ->sortBy(fn (Result $result): string => sprintf('%d-%010d-%010d', $result->swim_time === null ? 1 : 0,
                $result->swim_time ?? 0, $result->id))
            ->map(fn (Result $result): array => $this->followUp($result))
            ->values()
            ->all();

        return ['changed' => $changed->count(), 'notes' => $this->notes($outcomes)];
    }

    /**
     * @return array{foreign: list<string>, unchecked: bool, new: list<string>}
     *
     * @throws Throwable
     */
    private function followUp(Result $result): array
    {
        $result->load(['club', 'athlete.nation', 'athlete.club', 'swimEvent.strokeType', 'splits', 'meet']);
        $base = $result->club?->regional_record_type;

        $foreign = [];
        foreach (SwimRecord::where('result_id', $result->id)->get() as $record) {
            $ownType = in_array($record->record_type, self::NATIONAL_TYPES, true)
                || ($base !== null && in_array($record->record_type, [$base, $base.'.JR'], true));

            if ($ownType) {
                $record->update(['club_id' => $result->club_id]);
            } else {
                $foreign[] = $record->record_type;
            }
        }

        if (! $this->meetIsChecked($result)) {
            return ['foreign' => $foreign, 'unchecked' => true, 'new' => []];
        }

        ['new' => $new, 'pending' => $pending] = $this->checker->checkResult($result, $result->meet);
        $types = collect($new)->flatMap(fn (array $item): array => $item['types'])
            ->merge(collect($pending)->pluck('type'))
            ->values()
            ->all();

        return ['foreign' => $foreign, 'unchecked' => false, 'new' => $types];
    }

    /**
     * @param  list<array{foreign: list<string>, unchecked: bool, new: list<string>}>  $outcomes
     * @return list<string>
     */
    private function notes(array $outcomes): array
    {
        $notes = [];
        $outcomes = collect($outcomes);

        $foreign = $outcomes->flatMap(fn (array $o): array => $o['foreign'])->unique()->sort()->values();
        if ($foreign->isNotEmpty()) {
            $notes[] = 'Landesrekorde eines anderen Landesverbands ('.$foreign->implode(', ')
                .') aus den geänderten Ergebnissen wurden nicht geändert — bitte prüfen';
        }

        if ($outcomes->contains(fn (array $o): bool => $o['unchecked'])) {
            $notes[] = 'Der Rekord-Check für diesen Wettkampf ist noch nicht gelaufen';
        }

        $new = $outcomes->flatMap(fn (array $o): array => $o['new'])->countBy()->sortKeys();
        if ($new->isNotEmpty()) {
            $notes[] = 'Neue Rekorde: '.$new
                ->map(fn (int $count, string $type): string => $count > 1 ? $type.' ('.$count.')' : $type)
                ->implode(', ');
        }

        return $notes;
    }

    private function meetIsChecked(Result $result): bool
    {
        return SwimRecord::query()
            ->where(fn ($q) => $q
                ->whereIn('result_id', Result::select('id')->where('meet_id', $result->meet_id))
                ->orWhereIn('relay_result_id', $result->meet->relayResults()->select('id')))
            ->exists();
    }
}

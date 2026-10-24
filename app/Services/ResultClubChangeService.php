<?php

namespace App\Services;

use App\Models\Result;
use App\Models\SwimRecord;
use Throwable;

/**
 * ResultClubChangeService
 *
 * Folgearbeiten, nachdem der Verein eines Einzelergebnisses geändert wurde (z. B. ein per LENEX importiertes Ergebnis,
 * das unter dem Nationalteam ÖBSV statt unter dem Stammverein steht):
 *
 *   - Rekorde aus diesem Ergebnis bekommen den neuen Verein: die nationalen (AUT, AUT.JR) und die Landesrekorde des
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
     * @return list<string> Hinweise für die Erfolgsmeldung
     *
     * @throws Throwable
     */
    public function apply(Result $result): array
    {
        $result->load(['club', 'athlete.nation', 'athlete.club', 'swimEvent.strokeType', 'splits', 'meet']);
        $base = $result->club?->regional_record_type;
        $notes = [];

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

        if ($foreign !== []) {
            $notes[] = 'Aus diesem Ergebnis gibt es Landesrekorde eines anderen Landesverbands ('
                .implode(', ', $foreign).') — bitte prüfen, sie wurden nicht geändert';
        }

        if (! $this->meetIsChecked($result)) {
            return [...$notes, 'Der Rekord-Check für diesen Wettkampf ist noch nicht gelaufen'];
        }

        ['new' => $new, 'pending' => $pending] = $this->checker->checkResult($result, $result->meet);
        $types = collect($new)->flatMap(fn (array $item): array => $item['types'])
            ->merge(collect($pending)->pluck('type'))
            ->unique()->values();

        if ($types->isNotEmpty()) {
            $notes[] = 'Neue Rekorde: '.$types->implode(', ');
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

<?php

namespace App\Services;

use App\Models\SwimRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Chronologische Rekordkette einer Kategorie (record_type + stroke_type_id + sport_class + gender + course + distance
 * + relay_count). Die Kette sind die anerkannten Rekorde (APPROVED, APPROVED.HISTORY), nach set_date geordnet.
 *
 * Maßstab für ein Ergebnis ist der Rekord, der am Wettkampftag galt — nicht der heute aktuelle. Ein älterer Rekord
 * (nachträglicher Rekord-Check, bestätigter ausstehender Rekord) kommt an seinem Datum in die Kette; spätere Rekorde,
 * die gleich schnell oder langsamer sind, wären damals keiner gewesen und werden INVALID (Entscheidung Erik,
 * 25.10.2026, siehe docs/specs/split-records.md).
 */
final readonly class RecordChainService
{
    private const array CHAIN_STATUSES = ['APPROVED', 'APPROVED.HISTORY'];

    /**
     * Der am Datum geltende Rekord der Kategorie von $record: der schnellste anerkannte mit set_date <= $date
     * (Rekorde ohne Datum zählen als älter). Ohne Datum der schnellste der ganzen Kette.
     */
    public function validOn(SwimRecord $record, ?CarbonInterface $date): ?SwimRecord
    {
        return $this->chain($record)
            ->when($date !== null, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereNull('set_date')
                ->orWhereDate('set_date', '<=', $date->toDateString())))
            ->orderBy('swim_time')
            ->orderBy('id')
            ->first();
    }

    /**
     * Fügt einen anerkannten (APPROVED) Rekord an seinem Datum in die Kette ein und setzt das Rekord-Flag am
     * Ergebnis, falls er zu seiner Zeit ein Rekord war.
     *
     * - Ist der am Datum geltende Rekord gleich schnell oder schneller, war er nie ein Rekord: APPROVED.HISTORY.
     * - Sonst löst er den geltenden ab; spätere, gleich schnelle oder langsamere Rekorde werden INVALID.
     * - Ein späterer, schnellerer Rekord löst ihn ab (APPROVED.HISTORY); gibt es keinen, ist er der aktuelle.
     *
     * @return list<SwimRecord> die auf INVALID gesetzten späteren Rekorde
     *
     * @throws Throwable
     */
    public function insert(SwimRecord $record): array
    {
        return DB::transaction(function () use ($record): array {
            $previous = $this->validOn($record, $record->set_date);

            if ($previous !== null && $previous->swim_time <= $record->swim_time) {
                $record->update([
                    'is_current' => false,
                    'superseded_by_id' => $previous->id,
                    'record_status' => 'APPROVED.HISTORY',
                ]);

                return [];
            }

            $later = $record->set_date === null
                ? collect()
                : $this->chain($record)
                    ->whereDate('set_date', '>', $record->set_date->toDateString())
                    ->oldest('set_date')
                    ->orderBy('swim_time')
                    ->get();

            $invalidated = $later
                ->filter(fn (SwimRecord $other): bool => $other->swim_time >= $record->swim_time)
                ->each(fn (SwimRecord $other) => $this->invalidate($other, $record))
                ->values()
                ->all();

            /** @var SwimRecord|null $next */
            $next = $later->first(fn (SwimRecord $other): bool => $other->swim_time < $record->swim_time);

            $record->update([
                'supersedes_id' => $previous?->id,
                'superseded_by_id' => $next?->id,
                'is_current' => $next === null,
                'record_status' => $next === null ? 'APPROVED' : 'APPROVED.HISTORY',
            ]);

            $next?->update(['supersedes_id' => $record->id]);
            $previous?->markAsSupersededBy($record);

            // Neuer aktueller Rekord: alle übrigen geltenden der Kategorie sind langsamer (die älteren sind es nach
            // validOn(), die späteren wurden eben ungültig) und gehen in die Historie.
            if ($next === null) {
                $this->chain($record)
                    ->where('is_current', true)
                    ->get()
                    ->each(fn (SwimRecord $other) => $other->markAsSupersededBy($record));
            }

            $this->setResultFlag($record, true);

            return $invalidated;
        });
    }

    /**
     * Rekord-Flag am Ergebnis zu einem Rekordtyp.
     */
    public static function flagFor(string $recordType): string
    {
        return match (true) {
            $recordType === 'AUT' => 'is_national_record',
            $recordType === 'AUT.JR' => 'is_junior_record',
            str_ends_with($recordType, '.JR') => 'is_regional_junior_record',
            default => 'is_regional_record',
        };
    }

    /**
     * Anerkannte Rekorde der Kategorie von $record, ohne $record selbst.
     *
     * @return Builder<SwimRecord>
     */
    private function chain(SwimRecord $record): Builder
    {
        return SwimRecord::query()
            ->when($record->exists, fn (Builder $q) => $q->whereKeyNot($record->id))
            ->where('record_type', $record->record_type)
            ->where('stroke_type_id', $record->stroke_type_id)
            ->where('sport_class', $record->sport_class)
            ->where('gender', $record->gender)
            ->where('course', $record->course)
            ->where('distance', $record->distance)
            ->where('relay_count', $record->relay_count)
            ->whereIn('record_status', self::CHAIN_STATUSES);
    }

    /**
     * Nimmt einen späteren Rekord aus der Kette, der damals keiner gewesen wäre, und setzt das Rekord-Flag an seinem
     * Ergebnis zurück. Der Datensatz bleibt erhalten.
     */
    private function invalidate(SwimRecord $other, SwimRecord $older): void
    {
        $note = 'Ungültig: älterer Rekord '.$older->formatted_swim_time
            .($older->set_date !== null ? ' vom '.$older->set_date->format('d.m.Y') : '');

        $other->update([
            'record_status' => 'INVALID',
            'is_current' => false,
            'supersedes_id' => null,
            'superseded_by_id' => null,
            'comment' => Str::limit(collect([$note, $other->comment])->filter()->implode(' | '), 252),
        ]);

        // Nur zurücksetzen, wenn das Ergebnis keinen weiteren anerkannten Rekord desselben Typs hält.
        $stillRecord = SwimRecord::query()
            ->whereKeyNot($other->id)
            ->where('record_type', $other->record_type)
            ->whereIn('record_status', self::CHAIN_STATUSES)
            ->where(fn (Builder $q) => $q
                ->when($other->result_id !== null, fn (Builder $q) => $q->orWhere('result_id', $other->result_id))
                ->when($other->relay_result_id !== null, fn (Builder $q) => $q->orWhere('relay_result_id', $other->relay_result_id)))
            ->exists();

        if (! $stillRecord) {
            $this->setResultFlag($other, false);
        }
    }

    /**
     * Setzt bzw. löscht das zum Rekordtyp passende Flag am Ergebnis bzw. Staffelergebnis.
     */
    private function setResultFlag(SwimRecord $record, bool $value): void
    {
        $flag = self::flagFor($record->record_type);

        $record->result?->update([$flag => $value]);
        $record->relayResult?->update([$flag => $value]);
    }
}

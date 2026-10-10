<?php

namespace App\Services;

use App\Models\Club;
use App\Models\RecordSplit;
use App\Models\SwimRecord;

/**
 * Leitet aus einem neuen österreichischen Rekord (AUT, AUT.JR) die Landesrekorde ab — beim Rekordimport und beim
 * manuellen Eintragen.
 *
 * Landesverband: Verein des Rekords (bei Staffeln der Staffelverein), bei Einzelrekorden ohne Verein der Verein des
 * Athleten. Ohne Verein oder bei einem Verein ohne Landesverband (ÖBSV) gibt es keinen Landesrekord.
 * Geprüft wird immer der allgemeine Landesrekord, bei Jugendlichen (Rekordjahr − Geburtsjahr ≤ 18; bei Staffeln alle
 * Mitglieder mit Geburtsdatum) zusätzlich der Landes-Jugendrekord. Angelegt wird nur, wenn es in der Kategorie noch
 * keinen aktuellen Landesrekord gibt oder die Zeit schneller ist; der bisherige wird abgelöst. Der Landesrekord ist
 * eine Kopie des nationalen samt Splits und Staffelmitgliedern.
 */
final readonly class RegionalRecordService
{
    private const array NATIONAL_TYPES = ['AUT', 'AUT.JR'];

    private const int JUNIOR_MAX_AGE = 18;

    /**
     * @return list<string> Typen der angelegten Landesrekorde, z. B. ['AUT.KBSV', 'AUT.KBSV.JR']
     */
    public function propagate(SwimRecord $national): array
    {
        if (! in_array($national->record_type, self::NATIONAL_TYPES, true) || $national->record_status !== 'APPROVED') {
            return [];
        }

        $base = $this->club($national)?->regional_record_type;

        if ($base === null) {
            return [];
        }

        $types = $this->isJunior($national) ? [$base, $base.'.JR'] : [$base];
        $created = [];

        foreach ($types as $type) {
            if ($this->createIfFaster($national, $type)) {
                $created[] = $type;
            }
        }

        return $created;
    }

    private function club(SwimRecord $record): ?Club
    {
        if ($record->club_id !== null) {
            return $record->club;
        }

        return $record->relay_count > 1 ? null : $record->athlete?->club;
    }

    private function isJunior(SwimRecord $record): bool
    {
        if ($record->set_date === null) {
            return false;
        }

        $year = (int) $record->set_date->format('Y');

        if ($record->relay_count > 1) {
            $birthYears = $record->relayTeam()->whereNotNull('birth_date')->pluck('birth_date')
                ->map(fn ($date) => (int) substr((string) $date, 0, 4));

            return $birthYears->isNotEmpty() && $birthYears->every(fn (int $birthYear) => $year - $birthYear <= self::JUNIOR_MAX_AGE);
        }

        $birthDate = $record->athlete?->birth_date;

        return $birthDate !== null && $year - (int) $birthDate->format('Y') <= self::JUNIOR_MAX_AGE;
    }

    private function createIfFaster(SwimRecord $national, string $type): bool
    {
        $current = SwimRecord::query()
            ->where('record_type', $type)
            ->where('stroke_type_id', $national->stroke_type_id)
            ->where('sport_class', $national->sport_class)
            ->where('gender', $national->gender)
            ->where('course', $national->course)
            ->where('distance', $national->distance)
            ->where('relay_count', $national->relay_count)
            ->where('is_current', true)
            ->first();

        if ($current !== null && $current->swim_time <= $national->swim_time) {
            return false;
        }

        $regional = $national->replicate(['supersedes_id', 'superseded_by_id']);
        $regional->forceFill(['record_type' => $type, 'supersedes_id' => $current?->id, 'is_current' => true]);
        $regional->save();

        foreach ($national->splits as $split) {
            RecordSplit::query()->create([
                'swim_record_id' => $regional->id,
                'distance' => $split->distance,
                'split_time' => $split->split_time,
            ]);
        }

        foreach ($national->relayTeam as $member) {
            $copy = $member->replicate();
            $copy->swim_record_id = $regional->id;
            $copy->save();
        }

        $current?->markAsSupersededBy($regional);

        return true;
    }
}

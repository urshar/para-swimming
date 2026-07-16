<?php

namespace App\Services;

use App\Models\BaseTimeSportClass;
use App\Models\QualifyingTargetPoint;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Support\TimeParser;
use Illuminate\Validation\ValidationException;

/**
 * QualifyingTimeService
 *
 * CRUD-Logik für Richtzeitenlisten, Zielpunkte und Richtzeiten-Zeilen
 * (Phase 1 der Spec "Richtzeiten ÖSTM & ÖM"). Enthält bewusst noch keine
 * Berechnungslogik — die automatische Ermittlung folgt in Phase 2 und
 * baut auf denselben Basiswert-Strukturen wie WorldAquaticsPointsService auf.
 */
class QualifyingTimeService
{
    // ── Richtzeitenliste ──────────────────────────────────────────────────────

    public function createList(array $data): QualifyingTimeList
    {
        return QualifyingTimeList::create($data);
    }

    public function updateList(QualifyingTimeList $list, array $data): QualifyingTimeList
    {
        $list->update($data);

        return $list;
    }

    public function deleteList(QualifyingTimeList $list): void
    {
        $list->delete(); // kaskadiert Zielpunkte + Richtzeiten über cascadeOnDelete
    }

    // ── Zielpunkte ────────────────────────────────────────────────────────────

    /**
     * @throws ValidationException wenn der Sportklassen-Code ungültig ist
     */
    public function upsertTargetPoint(QualifyingTimeList $list, string $sportClass, int $points): QualifyingTargetPoint
    {
        $sportClass = $this->normalizeAndValidateSportClass($sportClass);

        return QualifyingTargetPoint::updateOrCreate(
            ['qualifying_time_list_id' => $list->id, 'sport_class' => $sportClass],
            ['points' => $points]
        );
    }

    public function deleteTargetPoint(QualifyingTargetPoint $targetPoint): void
    {
        $targetPoint->delete();
    }

    // ── Richtzeiten-Zeilen ────────────────────────────────────────────────────

    /**
     * @param  string|null  $time  Zeitstring im Anzeigeformat (z.B. "01:23.45") oder null
     *
     * @throws ValidationException wenn Sportklasse oder Zeitformat ungültig sind
     */
    public function upsertTime(
        QualifyingTimeList $list,
        int $strokeTypeId,
        int $distance,
        string $gender,
        string $sportClass,
        ?string $time,
    ): QualifyingTime {
        $sportClass = $this->normalizeAndValidateSportClass($sportClass);

        $valueCentiseconds = null;
        if ($time !== null && trim($time) !== '') {
            $valueCentiseconds = TimeParser::parse($time);
            if ($valueCentiseconds === null) {
                throw ValidationException::withMessages([
                    'value' => 'Ungültiges Zeitformat. Beispiel: 01:23.45',
                ]);
            }
        }

        return QualifyingTime::updateOrCreate(
            [
                'qualifying_time_list_id' => $list->id,
                'stroke_type_id' => $strokeTypeId,
                'distance' => $distance,
                'gender' => strtoupper($gender),
                'sport_class' => $sportClass,
            ],
            ['value_centiseconds' => $valueCentiseconds]
        );
    }

    public function deleteTime(QualifyingTime $time): void
    {
        $time->delete();
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Prüft das Format "S9"/"SB9"/"SM9" und, dass die Klassenzahl in der
     * bestehenden, admin-verwalteten Basiswert-Sportklassen-Tabelle
     * (base_time_sport_classes) existiert — keine Hardcodierung der Zahlen,
     * keine doppelte Sportklassen-Verwaltung.
     *
     * @throws ValidationException
     */
    private function normalizeAndValidateSportClass(string $sportClass): string
    {
        $upper = strtoupper(trim($sportClass));

        if (! preg_match('/^(S|SB|SM)(\d+)$/', $upper, $m)) {
            throw ValidationException::withMessages([
                'sport_class' => "Ungültige Sportklasse \"$sportClass\". Format: S, SB oder SM gefolgt von einer Zahl (z.B. S9, SB4, SM3).",
            ]);
        }

        $numericCode = 'S'.$m[2];

        if (! BaseTimeSportClass::where('code', $numericCode)->exists()) {
            throw ValidationException::withMessages([
                'sport_class' => "Sportklasse \"$upper\" ist keiner bekannten Basiswert-Sportklasse ($numericCode) zugeordnet. Bitte zuerst unter Basiswerte → Sportklassen anlegen.",
            ]);
        }

        return $upper;
    }
}

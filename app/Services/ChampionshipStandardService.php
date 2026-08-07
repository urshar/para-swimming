<?php

namespace App\Services;

use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Support\SportClassValidator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ChampionshipStandardService
 *
 * Anlegen und Pflege von Meisterschaften und ihren Qualifikationsnormen
 * (Phase 1 der Spec "WPS Qualification").
 *
 * Enthält bewusst noch keine Auswertung: Erfüllungsübersicht (§7) und
 * Auswahl-Rangliste (§8) sind eigene, rein lesende Services ab Phase 4.
 * Die Kopierfunktion (§9.1) gehört laut §14 zu Phase 2.
 */
final readonly class ChampionshipStandardService
{
    // ── Meisterschaft ─────────────────────────────────────────────────────────

    public function createChampionship(array $data): Championship
    {
        return Championship::query()->create($data);
    }

    public function updateChampionship(Championship $championship, array $data): Championship
    {
        $championship->update($data);

        return $championship;
    }

    public function deleteChampionship(Championship $championship): void
    {
        $championship->delete(); // kaskadiert die Normen über cascadeOnDelete
    }

    // ── Normen ────────────────────────────────────────────────────────────────

    /**
     * Legt eine Norm an oder aktualisiert sie anhand des fachlichen Schlüssels
     * (Bewerb × Geschlecht × Sportklasse).
     *
     * Setzt ausschließlich die übergebenen Felder. Insbesondere bleiben
     * obsv_percent, obsv_centiseconds und obsv_is_manual unberührt, wenn sie nicht
     * ausdrücklich mitgegeben werden — dieselbe Zusicherung, die der Import in
     * Phase 3 braucht (§9.2, Risiko Q-R3).
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException wenn Geschlecht oder Sportklasse ungültig sind
     */
    public function upsertStandard(
        Championship $championship,
        int $strokeTypeId,
        int $distance,
        string $gender,
        string $sportClass,
        array $values,
    ): ChampionshipStandard {
        $schluessel = [
            'championship_id' => $championship->getKey(),
            'stroke_type_id' => $strokeTypeId,
            'distance' => $distance,
            'gender' => SportClassValidator::normalizeGender($gender),
            'sport_class' => SportClassValidator::normalize($sportClass),
        ];

        $erlaubt = array_intersect_key($values, array_flip([
            'mqs_centiseconds',
            'met_centiseconds',
            'obsv_percent',
            'obsv_centiseconds',
            'obsv_is_manual',
            'notes',
        ]));

        return ChampionshipStandard::query()->updateOrCreate($schluessel, $erlaubt);
    }

    public function deleteStandard(ChampionshipStandard $standard): void
    {
        $standard->delete();
    }

    // ── ÖBSV-Verschärfung ─────────────────────────────────────────────────────

    /**
     * Legt den Prozentsatz fest und errechnet daraus die ÖBSV-Zeit (§5.3):
     *
     *     Zeit_ÖBSV = MQS × (1 − Prozentsatz / 100)
     *
     * obsv_is_manual wird auf false gesetzt — ab jetzt gilt wieder der Prozentsatz.
     *
     * Ohne MQS bleibt die Zeit null, der Prozentsatz wird dennoch gespeichert: Er ist
     * eine Festlegung, die auch dann gilt, wenn die MQS erst später nachgetragen wird.
     *
     * $percent = null setzt die Zeile ausdrücklich auf "offen" zurück, $percent = 0
     * übernimmt bewusst die MQS ([Q3]).
     */
    public function applyPercent(ChampionshipStandard $standard, ?float $percent): ChampionshipStandard
    {
        $mqs = $standard->getAttribute('mqs_centiseconds');

        $standard->update([
            'obsv_percent' => $percent,
            'obsv_centiseconds' => $this->calculateObsvTime($mqs, $percent),
            'obsv_is_manual' => false,
        ]);

        return $standard;
    }

    /**
     * Setzt die ÖBSV-Zeit von Hand (§5.3).
     *
     * obsv_is_manual wird auf true gesetzt; der Prozentsatz bleibt zur Information
     * erhalten, wird aber nicht mehr angewandt. Deshalb wird er hier bewusst nicht
     * neu berechnet und nicht gelöscht.
     */
    public function setObsvTimeManually(ChampionshipStandard $standard, ?int $centiseconds): ChampionshipStandard
    {
        $standard->update([
            'obsv_centiseconds' => $centiseconds,
            'obsv_is_manual' => true,
        ]);

        return $standard;
    }

    /**
     * Massenaktion "auf alle offenen Zeilen x % anwenden" (§5.3).
     *
     * Füllt ausschließlich Zeilen mit obsv_percent IS NULL. Von Hand gesetzte Werte
     * und bewusst auf 0 gesetzte Zeilen bleiben unangetastet — dasselbe Muster wie
     * bei den Umrechnungsfaktoren in wps-points (Risiko Q-R7).
     *
     * Bewusst zeilenweise statt als Massen-Update: obsv_centiseconds hängt von der
     * MQS der jeweiligen Zeile ab und ließe sich nicht in einem Statement setzen,
     * ohne DB-spezifische Ausdrücke zu verwenden (SQLite/MySQL-Portabilität).
     *
     * @return int Anzahl der geänderten Zeilen
     */
    public function applyPercentToOpenRows(Championship $championship, float $percent): int
    {
        $offene = $this->openStandards($championship);

        foreach ($offene as $standard) {
            $this->applyPercent($standard, $percent);
        }

        return $offene->count();
    }

    /**
     * Normen, bei denen die ÖBSV-Verschärfung noch nicht festgelegt ist.
     *
     * Ausschließlich obsv_percent IS NULL — ein Prozentsatz von 0 ist eine bewusste
     * Festlegung ("MQS übernommen") und damit keine offene Zeile ([Q3]).
     *
     * @return Collection<int, ChampionshipStandard>
     */
    public function openStandards(Championship $championship): Collection
    {
        return $championship->standards()
            ->whereNull('obsv_percent')
            ->get();
    }

    /**
     * Errechnet die ÖBSV-Zeit aus MQS und Prozentsatz.
     *
     * Gerundet wird mit floor(): Das Ergebnis ist nie langsamer als die exakt
     * errechnete Norm, die Verschärfung also nie zu lasch. Konsistent zur
     * Rundung in der WPS Points Engine.
     */
    public function calculateObsvTime(?int $mqsCentiseconds, ?float $percent): ?int
    {
        if ($mqsCentiseconds === null || $percent === null) {
            return null;
        }

        return (int) floor($mqsCentiseconds * (1 - $percent / 100));
    }
}

<?php

namespace App\Services;

use App\Models\PointSystem;
use App\Models\Result;

/**
 * ResultPointsService
 *
 * Berechnet beim Speichern eines einzelnen Ergebnisses die Punkte der Punktesysteme, die für
 * die Veranstaltung aktiviert sind (Reiter "Punkteberechnung" im Veranstaltungsformular):
 *
 *   - World Aquatics (beim ÖBSV die "ÖBSV-Punkte", auch Grundlage des ÖBSV Cups):
 *     1000 × (B/T)³ mit der Basiswert-Version des Wettkampfdatums → results.points.
 *     Ein manuell eingetragener Wert bleibt stehen; gerechnet wird nur, wenn das Feld leer ist.
 *   - WPS → results.wps_points (immer neu, über WpsPointCalculationService).
 *
 * Gibt die Gründe zurück, warum ein aktiviertes System keine Punkte liefern konnte (z. B. keine
 * Zeit bei DNS), damit der Controller sie in der Erfolgsmeldung nennen kann.
 */
final readonly class ResultPointsService
{
    public function __construct(
        private WorldAquaticsPointsService $worldAquatics,
        private WpsPointCalculationService $wps,
    ) {}

    /**
     * @param  bool  $manualPoints  true, wenn im Formular Punkte eingetragen wurden (dann keine WA-Berechnung)
     * @return list<string> Hinweise je aktiviertem System, das keine Punkte liefern konnte
     */
    public function calculate(Result $result, bool $manualPoints): array
    {
        $meet = $result->meet;
        $codes = $meet->pointSystems()->pluck('code');
        $notes = [];

        if (! $manualPoints && $codes->contains(PointSystem::CODE_WORLD_AQUATICS)) {
            [$points, $reason] = $this->worldAquatics->resolvePoints($result, $meet);
            $result->update(['points' => $points]);
            if ($points === null) {
                $notes[] = 'Punkte nicht berechnet: '.$reason;
            }
        }

        if ($codes->contains(PointSystem::CODE_WPS)) {
            $wps = $this->wps->recalculateForResult($result);
            if (! $wps->wasCalculated()) {
                $notes[] = 'WPS-Punkte nicht berechnet: '.$wps->skipReason;
            }
        }

        return $notes;
    }
}

<?php

namespace App\Services;

use App\Models\Meet;
use App\Models\PointSystem;
use App\Models\WpsPointVersion;

/**
 * Bestimmt, mit welcher WPS-Point-Score-Version ein Meet gerechnet wird.
 *
 * Priorität:
 *   1. explizit übergebene Version (manuelle Auswahl durch den Administrator)
 *   2. am Meet hinterlegte Version (meet_point_system.wps_point_version_id)
 *   3. Version, deren Gültigkeitszeitraum das Wettkampfdatum umfasst
 *   4. keine → Berechnung wird übersprungen
 *
 * Die manuelle Auswahl steht bewusst an erster Stelle: stünde sie hinter der automatischen
 * Zuordnung, wäre sie nie erreichbar. Dasselbe Verhalten zeigt der bestehende
 * WorldAquaticsPointsController mit seinem version_id-Override.
 */
final readonly class WpsPointVersionResolver
{
    public function resolveForMeet(Meet $meet, ?WpsPointVersion $explicit = null): ?WpsPointVersion
    {
        if ($explicit instanceof WpsPointVersion) {
            return $explicit;
        }

        return $this->fromMeetAssignment($meet) ?? $this->fromMeetDate($meet);
    }

    /**
     * Die zu einem beliebigen Datum gültige, nicht archivierte Version.
     *
     * Gebraucht von wps-qualification: Eine Meisterschaft hat kein Wettkampfdatum im Sinne
     * von Meet::start_date, wohl aber ein Ende des Qualifikationszeitraums — das ist der
     * Stichtag, zu dem die Normen bewertet werden.
     */
    public function resolveForDate(string $date): ?WpsPointVersion
    {
        return WpsPointVersion::query()
            ->active()
            ->validOn($date)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Die am Pivot meet_point_system hinterlegte Version.
     *
     * Archivierte Versionen werden hier bewusst nicht ausgefiltert: wurde eine Version einem
     * Meet ausdrücklich zugewiesen, soll sie auch nach der Archivierung weiter verwendet
     * werden — sonst würde eine Neuberechnung andere Punkte liefern als der Erstlauf.
     */
    private function fromMeetAssignment(Meet $meet): ?WpsPointVersion
    {
        $system = $meet->pointSystems()
            ->where('code', PointSystem::CODE_WPS)
            ->first();

        if ($system === null) {
            return null;
        }

        $versionId = $system->getRelation('pivot')->getAttribute('wps_point_version_id');

        if ($versionId === null) {
            return null;
        }

        return WpsPointVersion::find($versionId);
    }

    /**
     * Die zum Wettkampfdatum gültige, nicht archivierte Version.
     *
     * Überlappen sich Gültigkeitszeiträume, gewinnt die zuletzt begonnene — das ist der Fall,
     * wenn eine Korrekturversion unterjährig veröffentlicht wird.
     */
    private function fromMeetDate(Meet $meet): ?WpsPointVersion
    {
        if ($meet->start_date === null) {
            return null;
        }

        return $this->resolveForDate($meet->start_date->format('Y-m-d'));
    }
}

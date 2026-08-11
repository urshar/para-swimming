<?php

namespace App\Support;

use App\Models\Athlete;
use App\Models\Result;

/**
 * Ein Platz in einer WPS-Rangliste (Spec "WPS Rankings" §6).
 *
 * Wertobjekt statt assoziativem Array: Bei einem Array ist jeder Zugriff für die statische
 * Analyse ein `mixed` — Tippfehler in Schlüsseln fallen erst zur Laufzeit auf, und
 * Methodenaufrufe lassen sich nicht auflösen.
 *
 * Die Punkte werden **nicht** neu berechnet, sondern aus `results.wps_points` gelesen
 * (**[R3]**). Die verwendete Version steht am Ergebnis selbst; eine Rangliste, die still mit
 * einer anderen Version rechnet als am Ergebnis vermerkt, wäre nicht reproduzierbar.
 */
final readonly class WpsRankingEntry
{
    /**
     * @param  int|null  $rank  null, solange nicht platziert
     * @param  int  $points  WPS-Punkte aus dem Ergebnis
     * @param  int  $swimTime  geschwommene Zeit in Hundertstelsekunden
     * @param  string  $course  Bahnlänge, auf der geschwommen wurde
     * @param  int|null  $estimatedLcmTime  geschätzte Langbahnzeit bei umgerechneten
     *                                      Kurzbahnergebnissen (§4)
     * @param  string|null  $calculationType  official oder estimated
     * @param  string|null  $versionLabel  verwendete WPS-Punkteversion, für den Kopfbereich
     * @param  int|null  $age  Alter zum 31.12. des Wettkampfjahres (§5); null ohne Geburtsdatum
     */
    public function __construct(
        public ?int $rank,
        public Athlete $athlete,
        public Result $result,
        public int $points,
        public int $swimTime,
        public string $course,
        public ?int $estimatedLcmTime,
        public ?string $calculationType,
        public ?string $versionLabel,
        public string $eventLabel,
        public string $sportClass,
        public ?string $meetName,
        public ?string $meetDate,
        public ?int $age,
    ) {}

    public function isEstimated(): bool
    {
        return $this->calculationType === Result::WPS_TYPE_ESTIMATED;
    }

    /**
     * Athleten ohne Geburtsdatum werden nicht still verschluckt, sondern als Sammelposten
     * ausgewiesen (§5) — analog zur Regel im Statistikmodul, dass fehlende Zuordnungen
     * sichtbar bleiben.
     */
    public function hasAge(): bool
    {
        return $this->age !== null;
    }

    public function withRank(?int $rank): self
    {
        return new self(
            $rank,
            $this->athlete,
            $this->result,
            $this->points,
            $this->swimTime,
            $this->course,
            $this->estimatedLcmTime,
            $this->calculationType,
            $this->versionLabel,
            $this->eventLabel,
            $this->sportClass,
            $this->meetName,
            $this->meetDate,
            $this->age,
        );
    }
}

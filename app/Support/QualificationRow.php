<?php

namespace App\Support;

use App\Models\Athlete;
use App\Models\ChampionshipStandard;

/**
 * Eine Zeile der Erfüllungsübersicht: ein Athlet in einem Bewerb bewertet gegen die Norm
 * (Spec "WPS Qualification" §7).
 *
 * Bewusst ein Wertobjekt und kein assoziatives Array. Als Array ist jeder Zugriff für die
 * statische Analyse ein `mixed` — Tippfehler in Schlüsseln fallen erst zur Laufzeit auf, und
 * die Methoden von QualificationStatus lassen sich nicht auflösen.
 */
final readonly class QualificationRow
{
    /**
     * @param  string  $eventLabel  z.B. "100 m Freistil"
     * @param  string  $sportClass  wie im Ergebnis gespeichert, unverändert
     * @param  string|null  $wpsSportClass  nach der Zuordnung 21 → 14, nur für WPS-Zwecke
     * @param  ChampionshipStandard|null  $standard  null, wenn keine Norm ausgeschrieben ist
     * @param  int|null  $targetTimeOtherCourse  Zielzeit auf der abweichenden Bahnlänge (§6)
     * @param  bool|null  $metUsable  nur bei met_only gesetzt: Hat der Athlet anderswo die MQS?
     * @param  Athlete|null  $athlete  gesetzt, wo die Zeile für sich steht (Qualifikantenliste)
     */
    public function __construct(
        public string $eventLabel,
        public string $sportClass,
        public ?string $wpsSportClass,
        public ?ChampionshipStandard $standard,
        public ?int $targetTimeOtherCourse,
        public QualificationStatus $status,
        public ?bool $metUsable,
        public ?Athlete $athlete,
    ) {}

    /**
     * Kopie mit aufgelöster MET-Bewertung.
     *
     * Ob eine MET von Belang ist, steht erst fest, wenn alle Bewerbe eines Athleten bewertet
     * sind (§7.2) — deshalb wird sie nachträglich gesetzt statt im Konstruktor.
     */
    public function withMetUsable(?bool $metUsable): self
    {
        return new self(
            $this->eventLabel,
            $this->sportClass,
            $this->wpsSportClass,
            $this->standard,
            $this->targetTimeOtherCourse,
            $this->status,
            $metUsable,
            $this->athlete,
        );
    }

    /** Kopie mit gesetztem Athleten — für Listen, die nicht nach Athlet gruppiert sind. */
    public function withAthlete(Athlete $athlete): self
    {
        return new self(
            $this->eventLabel,
            $this->sportClass,
            $this->wpsSportClass,
            $this->standard,
            $this->targetTimeOtherCourse,
            $this->status,
            $this->metUsable,
            $athlete,
        );
    }
}

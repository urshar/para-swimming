<?php

namespace App\Support;

/**
 * Beste Leistung eines Athleten in einer Saison und einem Bewerb
 * (Spec "WPS Rankings" §7.3).
 *
 * Die Differenz zur Vorsaison wird nur zwischen aufeinanderfolgenden Saisonen **desselben
 * Bewerbs und derselben Sportklasse** gebildet. Bei einem Klassenwechsel bleibt sie null:
 * Eine Verbesserung um zweihundert Punkte, die allein aus einer Umklassifizierung stammt,
 * wäre eine Falschaussage über die Entwicklung (§7.2).
 */
final readonly class WpsAthleteSeasonEntry
{
    /**
     * @param  int  $year  Wettkampfjahr
     * @param  int|null  $pointsDelta  Punktdifferenz zur Vorsaison; null ohne Vergleichswert
     * @param  int|null  $timeDelta  Zeitdifferenz in Hundertsteln; negativ = schneller geworden
     * @param  bool  $classChanged  Sportklasse gegenüber der Vorsaison gewechselt
     */
    public function __construct(
        public int $year,
        public string $eventLabel,
        public string $sportClass,
        public int $swimTime,
        public string $course,
        public ?int $estimatedLcmTime,
        public int $points,
        public ?string $calculationType,
        public ?string $meetName,
        public ?string $meetDate,
        public ?int $pointsDelta,
        public ?int $timeDelta,
        public bool $classChanged,
        /** Kennung des zugrunde liegenden Ergebnisses — für die Zuordnung von Notizen. */
        public ?int $resultId = null,
    ) {}

    public function hasComparison(): bool
    {
        return $this->pointsDelta !== null;
    }

    public function improved(): bool
    {
        return $this->pointsDelta !== null && $this->pointsDelta > 0;
    }

    /**
     * Punktdifferenz als Text, z.B. "+34" oder "−12".
     *
     * Minuszeichen als U+2212, nicht als Bindestrich: In einer Tabelle mit Zahlen ist ein
     * Bindestrich zu leicht mit einem Trennstrich zu verwechseln.
     */
    public function formattedPointsDelta(): ?string
    {
        if ($this->pointsDelta === null) {
            return null;
        }

        return ($this->pointsDelta < 0 ? "\u{2212}" : '+').abs($this->pointsDelta);
    }

    /** Zeitdifferenz als Text in Sekunden, z.B. "−1,47 s". */
    public function formattedTimeDelta(): ?string
    {
        if ($this->timeDelta === null) {
            return null;
        }

        return sprintf(
            '%s%s s',
            $this->timeDelta < 0 ? "\u{2212}" : '+',
            number_format(abs($this->timeDelta) / 100, 2, ',', '.'),
        );
    }
}

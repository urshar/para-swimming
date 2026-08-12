<?php

namespace App\Support;

use App\Models\Championship;

/**
 * Eingaben der Förderauswertung (Spec "WPS Rankings" §6.6.1).
 *
 * Als Support-Objekt nach dem Vorbild von `ReportConfiguration`, damit Ansicht, Service und
 * PDF dieselbe Definition verwenden und ohne eigene Logik darüber iterieren.
 */
final readonly class WpsTalentReportConfiguration
{
    /** Jugend: 18 Jahre und jünger (§6.6.3). */
    public const int YOUTH_MAX_AGE = 18;

    public const string GROUP_YOUTH = 'Jugend';

    public const string GROUP_GENERAL = 'Allgemein';

    /** Die härtere WPS-Norm: Minimum Qualification Standard. */
    public const string NORM_MQS = 'mqs';

    /** Die weichere WPS-Norm: Minimum Entry Time. */
    public const string NORM_MET = 'met';

    /** @var list<string> */
    public const array NORM_TYPES = [self::NORM_MQS, self::NORM_MET];

    /** Vorschlagswerte aus §6.6.1. */
    public const float DEFAULT_YOUTH_THRESHOLD = 85.0;

    public const float DEFAULT_GENERAL_THRESHOLD = 95.0;

    /**
     * @param  int  $fromYear  Beginn des Auswertungszeitraums, auch mehrjährig
     * @param  int  $toYear  Ende des Auswertungszeitraums
     * @param  Championship  $reference  Referenznorm; ihre MQS liefert die Bezugspunktzahl
     * @param  float  $youthThreshold  Prozentsatz der Normpunktzahl für die Jugend
     * @param  float  $generalThreshold  Prozentsatz für die allgemeine Klasse
     * @param  string  $course  Bahnlänge der ausgewerteten Ergebnisse; Vorbelegung SCM (§4)
     */
    public function __construct(
        public int $fromYear,
        public int $toYear,
        public Championship $reference,
        public float $youthThreshold = self::DEFAULT_YOUTH_THRESHOLD,
        public float $generalThreshold = self::DEFAULT_GENERAL_THRESHOLD,
        public string $course = WpsRankingFilter::COURSE_SCM,
        /**
         * Welche der beiden WPS-Normen als Bezugsgröße dient.
         *
         * Die MQS ist die internationale Spitzennorm und für den österreichischen Nachwuchs
         * oft so scharf, dass die Prozentwerte im einstelligen Bereich landen und nichts mehr
         * unterscheiden. Die MET liegt näher an dem, was Nachwuchs erreichen kann. Welche
         * Kombination aus Norm und Schwelle brauchbare Listen ergibt, lässt sich nur an
         * echten Daten herausfinden — deshalb wählbar statt festgelegt.
         */
        public string $normType = self::NORM_MQS,
    ) {}

    /** Spaltenname der gewählten Norm in `championship_standards`. */
    public function normColumn(): string
    {
        return $this->normType === self::NORM_MET ? 'met_centiseconds' : 'mqs_centiseconds';
    }

    public function normLabel(): string
    {
        return $this->normType === self::NORM_MET ? 'MET' : 'MQS';
    }

    /**
     * Altersgruppe zu einem Alter (§6.6.3).
     *
     * Maßgeblich ist das Alter zum 31. Dezember des Jahres, in dem das **Ergebnis** erzielt
     * wurde — nicht des Zeitraumendes. Bei einer mehrjährigen Auswertung kann ein Athlet
     * damit in beiden Gruppen erscheinen: mit den Ergebnissen aus seinem 18. Lebensjahr in
     * der Jugend, mit den späteren in der allgemeinen Klasse. Das ist gewollt und bildet die
     * Entwicklung korrekt ab.
     */
    public function groupForAge(int $age): string
    {
        return $age <= self::YOUTH_MAX_AGE ? self::GROUP_YOUTH : self::GROUP_GENERAL;
    }

    /** Der Prozentsatz, der für diese Altersgruppe gilt. */
    public function thresholdPercentFor(string $group): float
    {
        return $group === self::GROUP_YOUTH ? $this->youthThreshold : $this->generalThreshold;
    }

    /**
     * Die Punktschwelle eines Bewerbs für eine Altersgruppe (§6.6.2).
     *
     *     Schwelle = Punkte(Normzeit) × Prozentsatz / 100
     *
     * Die Schwelle bezieht sich auf die **Punktzahl** der Normzeit, nicht auf einen
     * Prozentsatz der Zeit selbst: Punkte sind genau dafür gemacht, über Sportklassen und
     * Bewerbe hinweg vergleichbar zu sein. Ein Zeitprozentsatz bedeutet bei S3 etwas völlig
     * anderes als bei S14 und bei 50 m etwas anderes als bei 400 m.
     *
     * Abgerundet, damit ein Athlet, der die Schwelle genau trifft, sie auch erreicht.
     */
    public function thresholdPoints(int $normPoints, string $group): int
    {
        return (int) floor($normPoints * $this->thresholdPercentFor($group) / 100);
    }

    /**
     * Grenzen des Auswertungszeitraums als Datumsstrings.
     *
     * Die obere Grenze trägt eine Uhrzeit: Eine date-Spalte wird je nach Treiber als
     * "2026-12-31" oder als "2026-12-31 00:00:00" abgelegt, und ohne Uhrzeit fiele eine
     * Veranstaltung am 31. Dezember im zweiten Fall still aus der Auswertung.
     *
     * @return array{0: string, 1: string}
     */
    public function periodBounds(): array
    {
        return ["$this->fromYear-01-01", "$this->toYear-12-31 23:59:59"];
    }

    public function isMultiYear(): bool
    {
        return $this->toYear > $this->fromYear;
    }

    /**
     * Beschreibung für den Kopfbereich von Ansicht und PDF.
     *
     * Die Referenznorm wird ausdrücklich genannt — ein Prozentwert ohne Angabe seiner
     * Bezugsgröße ist wertlos (§6.6.1).
     */
    public function describe(): string
    {
        $zeitraum = $this->isMultiYear()
            ? "$this->fromYear bis $this->toYear"
            : (string) $this->fromYear;

        return sprintf(
            'Zeitraum %s · Referenznorm %s (%s) · Schwelle Jugend %s %% · Allgemein %s %% · Bahnlänge %s',
            $zeitraum,
            $this->reference->display_name,
            $this->normLabel(),
            $this->formatPercent($this->youthThreshold),
            $this->formatPercent($this->generalThreshold),
            $this->course,
        );
    }

    private function formatPercent(float $wert): string
    {
        return rtrim(rtrim(number_format($wert, 1, ',', '.'), '0'), ',');
    }
}

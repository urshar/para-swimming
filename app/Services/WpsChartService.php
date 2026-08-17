<?php

namespace App\Services;

use App\Models\AthletePerformanceNote;
use App\Support\TimeParser;
use App\Support\WpsAthleteSeasonEntry;
use App\Support\WpsChartPoint;
use App\Support\WpsChartSeries;
use Illuminate\Support\Collection;

/**
 * WpsChartService
 *
 * Rechnet die Zeilen eines Bewerbs in Bildkoordinaten um (Spec "WPS Rankings" §7.6).
 *
 * Die gesamte Rechnung liegt hier, nicht im Markup: Eine Achsenskalierung in Blade wäre
 * weder lesbar noch prüfbar. Blade zeichnet nur noch die fertigen Koordinaten.
 *
 * Kein JavaScript — die Grafik soll auch im PDF erscheinen, und dompdf führt keine Skripte
 * aus.
 */
final readonly class WpsChartService
{
    /** Die Zeit als Maß — sie liegt bei jedem Ergebnis vor. */
    public const string METRIC_TIME = 'time';

    /** Die WPS-Punkte als Maß — nur dort, wo sie berechnet wurden. */
    public const string METRIC_POINTS = 'points';

    /** Zahl der waagrechten Hilfslinien, einschließlich der beiden Ränder. */
    private const int GRID_LINES = 4;

    /**
     * Höchstzahl der Beschriftungen auf der Zeitachse.
     *
     * Bei vielen Starts überlappen sie sonst; gezeigt wird dann jede n-te.
     */
    private const int MAX_X_LABELS = 8;

    /**
     * Mindestspanne der Punkteachse.
     *
     * Ohne sie würde eine Schwankung von drei Punkten über die volle Höhe gespreizt und sähe
     * nach einem dramatischen Verlauf aus. Fünfzig Punkte sind eine Spanne, bei der ein
     * sichtbarer Ausschlag auch fachlich einer ist.
     */
    private const int MIN_SPAN_POINTS = 50;

    /**
     * Mindestspanne der Zeitachse in Hundertstelsekunden.
     *
     * Zwei Sekunden — darunter ist ein Ausschlag über die volle Höhe irreführend. Bei allen Zeiten
     * ist das noch wichtiger als bei Punkten: Über eine Saison schwankt eine 100-m-Zeit
     * häufig nur um wenige Zehntel.
     */
    private const int MIN_SPAN_TIME = 200;

    /**
     * @param  Collection<int, WpsAthleteSeasonEntry>  $entries  chronologisch
     * @param  Collection<int, AthletePerformanceNote>  $notes  Notizen des Athleten
     */
    /**
     * Bewusst ohne Vorgabewert: Welches Maß eine Grafik zeigt, ist ihre wesentliche
     * Eigenschaft — die Achse läuft je nach Maß in die andere Richtung. Ein stiller
     * Standardwert verberge an jeder Aufrufstelle genau diese Entscheidung.
     *
     * @param  string  $metric  METRIC_TIME oder METRIC_POINTS
     */
    public function series(
        string $eventLabel,
        Collection $entries,
        Collection $notes,
        string $metric,
    ): WpsChartSeries {
        $sortiert = $entries
            ->filter(static fn (WpsAthleteSeasonEntry $e): bool => $e->meetDate !== null)
            // Bei der Punkteansicht fallen Zeilen ohne Punktzahl heraus; bei der Zeitansicht
            // ist nichts auszuschließen, eine Zeit hat jedes Ergebnis.
            ->filter(static fn (WpsAthleteSeasonEntry $e): bool => $metric !== self::METRIC_POINTS
                || $e->hasPoints())
            ->sortBy(static fn (WpsAthleteSeasonEntry $e): string => (string) $e->meetDate)
            ->values();

        if ($sortiert->count() < 2) {
            return new WpsChartSeries($eventLabel, collect(), [], [], [], 0, 0, $metric);
        }

        [$min, $max] = $this->range($sortiert, $metric);

        $zeitpunkte = $sortiert
            ->map(static fn (WpsAthleteSeasonEntry $e): int => strtotime((string) $e->meetDate))
            ->all();

        $ersterTag = min($zeitpunkte);
        $letzterTag = max($zeitpunkte);

        $punkte = $sortiert->map(fn (WpsAthleteSeasonEntry $e): WpsChartPoint => new WpsChartPoint(
            $this->xFor(strtotime((string) $e->meetDate), $ersterTag, $letzterTag),
            $this->yFor($this->valueOf($e, $metric), $min, $max, $metric),
            $e->points,
            $e->swimTime,
            $e->sportClass,
            (string) $e->meetDate,
            $e->meetName,
            $e->classChanged,
            $e->calculationType === 'estimated',
        ));

        return new WpsChartSeries(
            $eventLabel,
            $punkte,
            $this->gridLines($min, $max, $metric),
            $this->xLabels($sortiert, $ersterTag, $letzterTag),
            $this->markers($sortiert, $notes, $ersterTag, $letzterTag),
            $min,
            $max,
            $metric,
        );
    }

    /** Der darzustellende Wert einer Zeile. */
    private function valueOf(WpsAthleteSeasonEntry $entry, string $metric): int
    {
        return $metric === self::METRIC_POINTS ? (int) $entry->points : $entry->swimTime;
    }

    /**
     * Die Punktespanne der Achse.
     *
     * Erweitert auf mindestens MIN_SPAN und auf glatte Zehner schritte gerundet, damit die
     * Hilfslinien lesbare Werte tragen.
     *
     * @param  Collection<int, WpsAthleteSeasonEntry>  $entries
     * @return array{0: int, 1: int}
     */
    private function range(Collection $entries, string $metric): array
    {
        $werte = $entries->map(fn (WpsAthleteSeasonEntry $e): int => $this->valueOf($e, $metric));

        $min = (int) $werte->min();
        $max = (int) $werte->max();
        $spanne = $max - $min;

        $mindestens = $metric === self::METRIC_POINTS ? self::MIN_SPAN_POINTS : self::MIN_SPAN_TIME;

        if ($spanne < $mindestens) {
            $fehlt = (int) ceil(($mindestens - $spanne) / 2);
            $min -= $fehlt;
            $max += $fehlt;
        }

        // Nach außen runden, damit die Hilfslinien lesbare Werte tragen: Punkte auf Zehner,
        // Zeiten auf ganze Sekunden. Nie unter null — negative Zeiten und Punktzahlen gibt es
        // nicht.
        $schritt = $metric === self::METRIC_POINTS ? 10 : 100;

        $min = max(0, (int) (floor($min / $schritt) * $schritt));
        $max = (int) (ceil($max / $schritt) * $schritt);

        return [$min, $max];
    }

    private function xFor(int $zeitpunkt, int $ersterTag, int $letzterTag): float
    {
        $breite = WpsChartSeries::WIDTH - WpsChartSeries::PADDING_LEFT - WpsChartSeries::PADDING_RIGHT;
        $spanne = max(1, $letzterTag - $ersterTag);

        return WpsChartSeries::PADDING_LEFT + ($zeitpunkt - $ersterTag) / $spanne * $breite;
    }

    /**
     * Bildkoordinate für eine Punktzahl.
     *
     * In SVG wächst y nach unten; mehr Punkte müssen also einen kleineren Wert ergeben —
     * daher die Umkehrung.
     */
    private function yFor(int $wert, int $min, int $max, string $metric): float
    {
        $hoehe = WpsChartSeries::HEIGHT - WpsChartSeries::PADDING_TOP - WpsChartSeries::PADDING_BOTTOM;
        $spanne = max(1, $max - $min);

        // Oben ist immer "besser". Bei Punkten heißt das: mehr; bei Zeiten: weniger. Die
        // Achse läuft deshalb je nach Maß in die andere Richtung — eine Zeitkurve, die bei
        // einer Verbesserung nach unten zeigte, würde jeder falsch lesen.
        $anteil = $metric === self::METRIC_POINTS
            ? ($max - $wert) / $spanne
            : ($wert - $min) / $spanne;

        return WpsChartSeries::PADDING_TOP + $anteil * $hoehe;
    }

    /**
     * @return list<array{y: float, label: int}>
     */
    private function gridLines(int $min, int $max, string $metric): array
    {
        $linien = [];
        $schritt = ($max - $min) / self::GRID_LINES;

        for ($i = 0; $i <= self::GRID_LINES; $i++) {
            $wert = (int) round($min + $schritt * $i);

            $linien[] = [
                'y' => $this->yFor($wert, $min, $max, $metric),
                'label' => $metric === self::METRIC_POINTS
                    ? (string) $wert
                    : TimeParser::display($wert),
            ];
        }

        return $linien;
    }

    /**
     * Beschriftungen der Zeitachse.
     *
     * Bei mehr als MAX_X_LABELS Starts wird ausgedünnt — überlappende Beschriftungen sind
     * unlesbar und dann schlechter als keine.
     *
     * @param  Collection<int, WpsAthleteSeasonEntry>  $entries
     * @return list<array{x: float, label: string}>
     */
    private function xLabels(Collection $entries, int $ersterTag, int $letzterTag): array
    {
        $schrittweite = max(1, (int) ceil($entries->count() / self::MAX_X_LABELS));
        $beschriftungen = [];

        foreach ($entries as $index => $eintrag) {
            if ($index % $schrittweite !== 0 && $index !== $entries->count() - 1) {
                continue;
            }

            $zeitpunkt = strtotime((string) $eintrag->meetDate);

            $beschriftungen[] = [
                'x' => $this->xFor($zeitpunkt, $ersterTag, $letzterTag),
                'label' => date('m/y', $zeitpunkt),
            ];
        }

        return $beschriftungen;
    }

    /**
     * Senkrechte Markierungen: Klassenwechsel und Notizen.
     *
     * Ein Klassenwechsel gehört markiert, weil die Kurve dort einen Sprung macht, der keine
     * Leistungsentwicklung ist. Notizen erklären Ausschläge, die sonst unerklärt blieben.
     *
     * Notizen außerhalb des dargestellten Zeitraums werden weggelassen — eine Markierung am
     * Rand behauptete einen Bezug, den es nicht gibt.
     *
     * @param  Collection<int, WpsAthleteSeasonEntry>  $entries
     * @param  Collection<int, AthletePerformanceNote>  $notes
     * @return list<array{x: float, label: string}>
     */
    private function markers(Collection $entries, Collection $notes, int $ersterTag, int $letzterTag): array
    {
        $markierungen = [];

        foreach ($entries as $eintrag) {
            if (! $eintrag->classChanged) {
                continue;
            }

            $markierungen[] = [
                'x' => $this->xFor(strtotime((string) $eintrag->meetDate), $ersterTag, $letzterTag),
                'label' => 'Klassenwechsel '.$eintrag->sportClass,
            ];
        }

        foreach ($notes as $notiz) {
            $zeitpunkt = strtotime($notiz->getAttribute('noted_on')->format('Y-m-d'));

            if ($zeitpunkt < $ersterTag || $zeitpunkt > $letzterTag) {
                continue;
            }

            $markierungen[] = [
                'x' => $this->xFor($zeitpunkt, $ersterTag, $letzterTag),
                'label' => $notiz->categoryLabel(),
            ];
        }

        return $markierungen;
    }
}

<?php

namespace App\Services;

use App\Models\WpsScmConversionFactor;
use App\Support\WpsSportClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ermittelt Umrechnungsfaktoren aus den eigenen Ergebnissen (Spec §9.3).
 *
 * Grundlage sind Athletinnen und Athleten, die denselben Bewerb in derselben Sportklasse
 * sowohl auf der Lang- als auch auf der Kurzbahn geschwommen sind. Der Faktor ist der MEDIAN
 * der Einzelverhältnisse `LCM-Zeit / SCM-Zeit`.
 *
 * Zwei Vorkehrungen, ohne die das Verfahren nicht den Bahnunterschied misst:
 *
 * 1. ZEITFENSTER. Verglichen werden nur Zeiten, die höchstens `window_months` auseinander
 *    liegen. Sonst fließt die Leistungsentwicklung zwischen den beiden Starts mit ein — ein
 *    Nachwuchsathlet mit einer alten Langbahnzeit und einer aktuellen Kurzbahn-Bestzeit
 *    lieferte einen viel zu hohen Faktor.
 *
 * 2. PLAUSIBILITÄTSGRENZEN. Einzelverhältnisse außerhalb von `min_ratio`/`max_ratio` fließen
 *    nicht in den Median ein. Auf 100 m hat die Kurzbahn zwei Wenden mehr; daraus ergeben
 *    sich realistisch ein bis drei Prozent. Ein Verhältnis von 1,08 beruht praktisch immer
 *    auf einem Vergleich ungleicher Formzustände.
 *
 * Verworfene Paare werden gezählt und im Faktorenbericht ausgewiesen.
 *
 * Bleibende Einschränkung (Spec §9.6): Athleten mit Zeiten auf beiden Bahnlängen sind
 * überwiegend jene, die international starten. Für den Nachwuchs fällt ein so ermittelter
 * Faktor tendenziell zu optimistisch aus.
 */
final readonly class WpsScmFactorCalibrationService
{
    /** Ergebnisstatus ohne wertbare Leistung. EXH fehlt bewusst — es liegt eine Zeit vor. */
    private const array NON_SCORING_STATUSES = ['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'];

    public function minSampleSize(): int
    {
        return (int) config('wps.calibration.min_sample_size', 3);
    }

    /**
     * Untergrenze für den errechneten Median.
     *
     * Ein Faktor unter 1 hieße, dass auf der Kurzbahn langsamer geschwommen wird — als
     * Bahneffekt ausgeschlossen.
     */
    public function minMedian(): float
    {
        return (float) config('wps.calibration.min_median', 1.0);
    }

    public function windowMonths(): int
    {
        return (int) config('wps.calibration.window_months', 6);
    }

    /** @return array{min: float, max: float} */
    public function plausibleRange(): array
    {
        return [
            'min' => (float) config('wps.calibration.min_ratio', 0.98),
            'max' => (float) config('wps.calibration.max_ratio', 1.06),
        ];
    }

    /**
     * Beobachtete Verhältnisse je Kombination aus Bewerb und Sportklasse.
     *
     * @return Collection<string, array{
     *     stroke_type_id: int, lenex_code: string, distance: int, sport_class: string,
     *     sample_size: int, median: float, min: float, max: float, rejected: int,
     *     plausible_median: bool
     * }>
     */
    public function observedRatios(): Collection
    {
        return $this->athletePairs()
            ->groupBy(static fn (array $paar): string => $paar['stroke_type_id']
                .'|'.$paar['distance']
                .'|'.$paar['sport_class'])
            ->map(function (Collection $gruppe): ?array {
                $grenzen = $this->plausibleRange();

                $plausibel = $gruppe
                    ->filter(static fn (array $paar): bool => $paar['ratio'] >= $grenzen['min']
                        && $paar['ratio'] <= $grenzen['max'])
                    ->values();

                $verworfen = $gruppe->count() - $plausibel->count();

                if ($plausibel->isEmpty()) {
                    return null;
                }

                $verhaeltnisse = $plausibel->pluck('ratio')->sort()->values();
                $erste = $plausibel->first();

                return [
                    'stroke_type_id' => $erste['stroke_type_id'],
                    'lenex_code' => $erste['lenex_code'],
                    'distance' => $erste['distance'],
                    'sport_class' => $erste['sport_class'],
                    'sample_size' => $verhaeltnisse->count(),
                    'median' => $this->median($verhaeltnisse),
                    'min' => (float) $verhaeltnisse->first(),
                    'max' => (float) $verhaeltnisse->last(),
                    'rejected' => $verworfen,
                    // Ein Median unter 1 misst Formunterschiede, nicht die Bahnlänge.
                    'plausible_median' => $this->median($verhaeltnisse) >= $this->minMedian(),
                ];
            })
            ->filter()
            ->sortByDesc('sample_size');
    }

    /**
     * Schreibt für alle ausreichend belegten Kombinationen einen Faktor aus eigenen Daten.
     *
     * Faktoren mit source = manual bleiben unangetastet, damit eine bewusste Korrektur nicht
     * überschrieben wird.
     *
     * @return array{created: int, updated: int, skipped: int, rejected_pairs: int, implausible_medians: int}
     */
    public function calibrate(): array
    {
        $ergebnis = [
            'created' => 0, 'updated' => 0, 'skipped' => 0,
            'rejected_pairs' => 0, 'implausible_medians' => 0,
        ];

        foreach ($this->observedRatios() as $beobachtung) {
            $ergebnis['rejected_pairs'] += $beobachtung['rejected'];

            if ($beobachtung['sample_size'] < $this->minSampleSize()) {
                $ergebnis['skipped']++;

                continue;
            }

            // Kein Faktor unter 1 — die Kombination fällt auf den Sammelwert je Stil zurück.
            if (! $beobachtung['plausible_median']) {
                $ergebnis['implausible_medians']++;
                $ergebnis['skipped']++;

                continue;
            }

            $merkmale = [
                'stroke_type_id' => $beobachtung['stroke_type_id'],
                'distance' => $beobachtung['distance'],
                'sport_class' => $beobachtung['sport_class'],
                'gender' => null,
            ];

            $vorhanden = WpsScmConversionFactor::where($merkmale)->first();

            if ($vorhanden !== null && $vorhanden->source === WpsScmConversionFactor::SOURCE_MANUAL) {
                $ergebnis['skipped']++;

                continue;
            }

            WpsScmConversionFactor::updateOrCreate($merkmale, [
                'factor' => round($beobachtung['median'], 5),
                'source' => WpsScmConversionFactor::SOURCE_OWN_DATA,
                'sample_size' => $beobachtung['sample_size'],
                'confidence_level' => $this->confidenceFor($beobachtung['sample_size']),
                'active' => true,
            ]);

            $vorhanden === null ? $ergebnis['created']++ : $ergebnis['updated']++;
        }

        return $ergebnis;
    }

    /**
     * Stellt angesetzte und beobachtete Faktoren gegenüber (Faktorenbericht, Spec §9.7).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function report(WpsScmConversionService $conversionService): Collection
    {
        return $this->observedRatios()
            ->map(function (array $beobachtung) use ($conversionService): array {
                $angesetzt = $conversionService->resolveFactor(
                    $beobachtung['stroke_type_id'],
                    $beobachtung['distance'],
                    $beobachtung['sport_class'],
                    'M',
                );

                return $beobachtung + [
                    'applied_factor' => $angesetzt?->factor,
                    'applied_source' => $angesetzt?->source,
                    'applied_sample_size' => $angesetzt?->sample_size,
                    'deviation' => $angesetzt !== null
                        ? $beobachtung['median'] - $angesetzt->factor
                        : null,
                    'sufficient' => $beobachtung['sample_size'] >= $this->minSampleSize()
                        && $beobachtung['plausible_median'],
                ];
            })
            ->values();
    }

    /**
     * Je Athlet, Bewerb und Sportklasse das zeitlich engste Paar aus LCM- und SCM-Ergebnis.
     *
     * Bewusst NICHT die jeweilige Bestzeit über die gesamte Historie: Zwei Bestzeiten aus
     * verschiedenen Jahren messen die Entwicklung des Athleten, nicht den Bahnunterschied.
     * Gesucht wird deshalb das Paar mit dem geringsten zeitlichen Abstand innerhalb des
     * konfigurierten Fensters.
     *
     * @return Collection<int, array{stroke_type_id: int, lenex_code: string, distance: int, sport_class: string, ratio: float, gap_days: int}>
     */
    private function athletePairs(): Collection
    {
        $ergebnisse = DB::table('results as r')
            ->join('meets as m', 'm.id', '=', 'r.meet_id')
            ->join('swim_events as e', 'e.id', '=', 'r.swim_event_id')
            ->join('stroke_types as s', 's.id', '=', 'e.stroke_type_id')
            ->whereNotNull('r.swim_time')
            ->where('r.swim_time', '>', 0)
            ->where('e.relay_count', 1)
            ->whereNotNull('r.sport_class')
            ->whereNotNull('m.start_date')
            ->whereIn('m.course', ['LCM', 'SCM'])
            // Zwingend gruppiert: ein freistehendes orWhere würde die gesamte vorangehende
            // Bedingungskette zu einer ODER-Verknüpfung machen. NOT IN liefert bei NULL
            // zudem nie true, daher die zusätzliche Null-Prüfung.
            ->where(static function ($query): void {
                $query->whereNull('r.status')
                    ->orWhereNotIn('r.status', self::NON_SCORING_STATUSES);
            })
            ->select([
                'r.athlete_id', 'e.stroke_type_id', 's.lenex_code', 'e.distance',
                'r.sport_class', 'm.course', 'r.swim_time', 'm.start_date',
            ])
            ->get();

        $fenster = $this->windowMonths();

        return $ergebnisse
            ->map(static function (object $zeile): ?object {
                $sportClass = WpsSportClass::mapToWps($zeile->sport_class);

                if ($sportClass === null) {
                    return null;
                }

                $zeile->sport_class = $sportClass;

                return $zeile;
            })
            ->filter()
            ->groupBy(static fn (object $zeile): string => $zeile->athlete_id
                .'|'.$zeile->stroke_type_id
                .'|'.$zeile->distance
                .'|'.$zeile->sport_class)
            ->map(static function (Collection $gruppe) use ($fenster): ?array {
                $lcm = $gruppe->where('course', 'LCM');
                $scm = $gruppe->where('course', 'SCM');

                if ($lcm->isEmpty() || $scm->isEmpty()) {
                    return null;
                }

                $bestes = null;

                foreach ($lcm as $langbahn) {
                    foreach ($scm as $kurzbahn) {
                        $abstand = Carbon::parse($langbahn->start_date)
                            ->diffInDays(Carbon::parse($kurzbahn->start_date), absolute: true);

                        if ($abstand > $fenster * 30 || (int) $kurzbahn->swim_time <= 0) {
                            continue;
                        }

                        if ($bestes === null || $abstand < $bestes['gap_days']) {
                            $bestes = [
                                'stroke_type_id' => (int) $langbahn->stroke_type_id,
                                'lenex_code' => (string) $langbahn->lenex_code,
                                'distance' => (int) $langbahn->distance,
                                'sport_class' => (string) $langbahn->sport_class,
                                'ratio' => (int) $langbahn->swim_time / (int) $kurzbahn->swim_time,
                                'gap_days' => (int) $abstand,
                            ];
                        }
                    }
                }

                return $bestes;
            })
            ->filter()
            ->values();
    }

    /** @param  Collection<int, float>  $werte  aufsteigend sortiert */
    private function median(Collection $werte): float
    {
        $anzahl = $werte->count();
        $mitte = intdiv($anzahl, 2);

        return $anzahl % 2 === 1
            ? (float) $werte[$mitte]
            : ((float) $werte[$mitte - 1] + (float) $werte[$mitte]) / 2;
    }

    private function confidenceFor(int $sampleSize): string
    {
        return match (true) {
            $sampleSize >= 8 => WpsScmConversionFactor::CONFIDENCE_MEDIUM,
            default => WpsScmConversionFactor::CONFIDENCE_LOW,
        };
    }
}

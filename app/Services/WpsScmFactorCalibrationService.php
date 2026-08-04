<?php

namespace App\Services;

use App\Models\WpsScmConversionFactor;
use App\Support\WpsSportClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ermittelt Umrechnungsfaktoren aus den eigenen Ergebnissen (Spec §9.3).
 *
 * Datengrundlage sind Athletinnen und Athleten, die in derselben Sportklasse und demselben
 * Bewerb sowohl eine Langbahn- als auch eine Kurzbahnzeit haben. Je Athlet wird
 * `beste LCM-Zeit / beste SCM-Zeit` gebildet; der Faktor ist der MEDIAN dieser Verhältnisse.
 *
 * Median statt Mittelwert: Bei drei bis neun Athleten würde ein einzelner Ausreißer — etwa
 * eine Zeit aus einem Formtief — den Mittelwert spürbar verziehen.
 *
 * Wichtige Einschränkung (Spec §9.6): Athleten mit Zeiten auf beiden Bahnlängen sind
 * überwiegend jene, die international gestartet sind, also die nationale Spitze. Für den
 * Nachwuchs fällt ein so ermittelter Faktor tendenziell zu optimistisch aus.
 */
final readonly class WpsScmFactorCalibrationService
{
    /** Unter dieser Zahl von Athleten wird kein eigener Faktor gebildet. */
    public const int MIN_SAMPLE_SIZE = 3;

    /** Ergebnisstatus ohne wertbare Leistung. EXH fehlt bewusst — es liegt eine Zeit vor. */
    private const array NON_SCORING_STATUSES = ['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'];

    /**
     * Beobachtete Verhältnisse je Kombination aus Bewerb und Sportklasse.
     *
     * @return Collection<string, array{
     *     stroke_type_id: int, lenex_code: string, distance: int, sport_class: string,
     *     sample_size: int, median: float, min: float, max: float
     * }>
     */
    public function observedRatios(): Collection
    {
        return $this->athleteRatios()
            ->groupBy(static fn (array $zeile): string => $zeile['stroke_type_id']
                .'|'.$zeile['distance']
                .'|'.$zeile['sport_class'])
            ->map(function (Collection $gruppe): array {
                $verhaeltnisse = $gruppe->pluck('ratio')->sort()->values();
                $erste = $gruppe->first();

                return [
                    'stroke_type_id' => $erste['stroke_type_id'],
                    'lenex_code' => $erste['lenex_code'],
                    'distance' => $erste['distance'],
                    'sport_class' => $erste['sport_class'],
                    'sample_size' => $verhaeltnisse->count(),
                    'median' => $this->median($verhaeltnisse),
                    'min' => (float) $verhaeltnisse->first(),
                    'max' => (float) $verhaeltnisse->last(),
                ];
            })
            ->sortByDesc('sample_size');
    }

    /**
     * Schreibt für alle ausreichend belegten Kombinationen einen Faktor aus eigenen Daten.
     *
     * Bestehende Einträge derselben Kombination werden aktualisiert; Faktoren mit
     * source = manual bleiben unangetastet, damit eine bewusste Korrektur nicht
     * überschrieben wird.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function calibrate(): array
    {
        $ergebnis = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($this->observedRatios() as $beobachtung) {
            if ($beobachtung['sample_size'] < self::MIN_SAMPLE_SIZE) {
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

                $abweichung = $angesetzt !== null
                    ? $beobachtung['median'] - $angesetzt->factor
                    : null;

                return $beobachtung + [
                    'applied_factor' => $angesetzt?->factor,
                    'applied_source' => $angesetzt?->source,
                    'applied_sample_size' => $angesetzt?->sample_size,
                    'deviation' => $abweichung,
                    'sufficient' => $beobachtung['sample_size'] >= self::MIN_SAMPLE_SIZE,
                ];
            })
            ->values();
    }

    /**
     * Ein Verhältnis je Athlet, Bewerb und Sportklasse.
     *
     * Bewusst in PHP zusammengeführt statt per SQL-Fensterfunktion: Die Datenmenge ist klein,
     * und die Abfrage bleibt auf MySQL wie auf SQLite identisch.
     *
     * @return Collection<int, array{stroke_type_id: int, lenex_code: string, distance: int, sport_class: string, ratio: float}>
     */
    private function athleteRatios(): Collection
    {
        $bestzeiten = DB::table('results as r')
            ->join('meets as m', 'm.id', '=', 'r.meet_id')
            ->join('swim_events as e', 'e.id', '=', 'r.swim_event_id')
            ->join('stroke_types as s', 's.id', '=', 'e.stroke_type_id')
            ->whereNotNull('r.swim_time')
            ->where('r.swim_time', '>', 0)
            ->where('e.relay_count', 1)
            ->whereNotNull('r.sport_class')
            ->whereIn('m.course', ['LCM', 'SCM'])
            // Zwingend in eine Gruppierung gefasst: ein freistehendes orWhere würde die
            // gesamte vorangehende Bedingungskette zu einer ODER-Verknüpfung machen. In SQL
            // liefert NOT IN bei NULL zudem nie true — die Null-Prüfung ist also nötig.
            ->where(static function ($query): void {
                $query->whereNull('r.status')
                    ->orWhereNotIn('r.status', self::NON_SCORING_STATUSES);
            })
            ->select([
                'r.athlete_id',
                'e.stroke_type_id',
                's.lenex_code',
                'e.distance',
                'r.sport_class',
                'm.course',
            ])
            ->selectRaw('MIN(r.swim_time) as best_time')
            ->groupBy([
                'r.athlete_id', 'e.stroke_type_id', 's.lenex_code',
                'e.distance', 'r.sport_class', 'm.course',
            ])
            ->get();

        return $bestzeiten
            ->groupBy(static fn (object $zeile): string => $zeile->athlete_id
                .'|'.$zeile->stroke_type_id
                .'|'.$zeile->distance
                .'|'.$zeile->sport_class)
            ->map(static function (Collection $gruppe): ?array {
                $lcm = $gruppe->firstWhere('course', 'LCM');
                $scm = $gruppe->firstWhere('course', 'SCM');

                if ($lcm === null || $scm === null || (int) $scm->best_time <= 0) {
                    return null;
                }

                // Dieselbe Abbildung wie bei der Berechnung: Sonst entstünde ein Faktor für
                // eine Klasse, die zur Rechenzeit nie abgefragt wird — und die betroffenen
                // Athleten fehlten in der Stichprobe der Zielklasse.
                $sportClass = WpsSportClass::mapToWps($lcm->sport_class);

                if ($sportClass === null) {
                    return null;
                }

                return [
                    'stroke_type_id' => (int) $lcm->stroke_type_id,
                    'lenex_code' => (string) $lcm->lenex_code,
                    'distance' => (int) $lcm->distance,
                    'sport_class' => $sportClass,
                    'ratio' => (int) $lcm->best_time / (int) $scm->best_time,
                ];
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

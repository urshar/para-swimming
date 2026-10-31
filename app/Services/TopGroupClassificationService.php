<?php

namespace App\Services;

use App\Enums\TopGroupReason;
use App\Models\Athlete;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupTopGroupClassification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TopGroupClassificationService
 *
 * Bestimmt zu Saisonbeginn, welche Athleten in die Top-Gruppe eines Cup-Jahres fallen (Spec cup-top-group.md):
 *
 *   1. Hand-Einstufung (manual_is_top_group) gilt vor allem anderen und bleibt bei Neuberechnung erhalten.
 *   2. Nationalkader (Zugehörigkeit irgendwann im Cup-Jahr) → immer Top-Gruppe.
 *   3. Zwei-Jahres-Regel gegen den Vorjahresstatus (wirksame Einstufung im vorigen Cup):
 *      - kein Start im vorigen Cup → Status bleibt;
 *      - Start im vorigen Cup und im letzten Cup davor mit Start: beide über der Grenze → Top,
 *        beide darunter → nicht Top, sonst Status bleibt.
 *      Jahreswert ist die beste Tageswertung, gemessen an der Grenze des einzustufenden Cups.
 *
 * Fehlen Einstufungen früherer Cups, werden sie vorab vom ältesten an nachgerechnet; bestehende bleiben unverändert.
 *
 * MUSS vor DailyRankingService::calculateForMeet() für dasselbe Cup-Jahr laufen, da GroupResolverService::isTopGroup()
 * auf diesen Snapshot zugreift. "Ausländischer Verein" ist bewusst NICHT Teil dieser saisonalen Einstufung — das bleibt
 * eine sofortige, ergebnisbezogene Prüfung.
 */
final readonly class TopGroupClassificationService
{
    /**
     * @return EloquentCollection<int, CupTopGroupClassification>
     *
     * @throws Throwable bei einem Fehler innerhalb der Transaktion
     */
    public function calculateForCup(Cup $cup): EloquentCollection
    {
        DB::transaction(function () use ($cup) {
            foreach ($this->predecessorsWithoutClassification($cup) as $predecessor) {
                $this->classify($predecessor);
            }

            $this->classify($cup);
        });

        return CupTopGroupClassification::where('cup_id', $cup->id)->get();
    }

    /**
     * Frühere Cups ohne jede Einstufung, die calculateForCup() vorab nachrechnet (ältester zuerst).
     *
     * @return EloquentCollection<int, Cup>
     */
    public function predecessorsWithoutClassification(Cup $cup): EloquentCollection
    {
        return Cup::where('year', '<', $cup->year)
            ->whereDoesntHave('topGroupClassifications')
            ->orderBy('year')
            ->get();
    }

    /**
     * Lädt die Klassifizierung eines Cups als Map (athlete_id => is_top_group),
     * damit GroupResolverService bei Massenverarbeitung nicht pro Ergebnis
     * einzeln nachfragen muss.
     *
     * @return Collection<int, bool>
     */
    public function loadClassificationMap(Cup $cup): Collection
    {
        return CupTopGroupClassification::where('cup_id', $cup->id)
            ->get(['athlete_id', 'is_top_group'])
            ->mapWithKeys(fn (CupTopGroupClassification $row) => [$row->athlete_id => $row->is_top_group]);
    }

    /** Stuft einen einzelnen Cup ein; setzt voraus, dass der vorige Cup bereits eingestuft ist. */
    private function classify(Cup $cup): void
    {
        $manualRows = CupTopGroupClassification::where('cup_id', $cup->id)
            ->whereNotNull('manual_is_top_group')
            ->get()
            ->keyBy('athlete_id');

        CupTopGroupClassification::where('cup_id', $cup->id)->whereNull('manual_is_top_group')->delete();

        $previousCup = Cup::where('year', '<', $cup->year)->orderByDesc('year')->first();
        $previousStatus = $previousCup
            ? $this->loadClassificationMap($previousCup)
            : collect();
        $startsByAthlete = $this->bestPointsPerEarlierCup($cup);
        $kaderAthleteIds = $this->kaderAthleteIdsDuring($cup->year);

        $startedInPreviousCup = $previousCup
            ? $startsByAthlete->filter(fn (Collection $starts) => $starts->has($previousCup->year))->keys()
            : collect();

        $athleteIds = $kaderAthleteIds
            ->merge($manualRows->keys())
            ->merge($previousStatus->filter()->keys())
            ->merge($startedInPreviousCup)
            ->unique();

        $calculatedAt = now();

        foreach ($athleteIds as $athleteId) {
            $starts = $startsByAthlete->get($athleteId, collect());
            $lastStartYear = $starts->keys()->first();
            $priorStartYear = $starts->keys()->skip(1)->first();
            $wasTopGroup = $previousCup ? $previousStatus->get($athleteId, false) : null;

            [$calculated, $reason] = $this->applyRule(
                $kaderAthleteIds->contains($athleteId),
                $wasTopGroup,
                $previousCup !== null && $lastStartYear === $previousCup->year,
                $starts->get($lastStartYear),
                $starts->get($priorStartYear),
                $cup->top_group_points_threshold,
            );

            $manual = $manualRows->get($athleteId);

            CupTopGroupClassification::updateOrCreate(
                ['cup_id' => $cup->id, 'athlete_id' => $athleteId],
                [
                    'is_top_group' => $manual?->manual_is_top_group ?? $calculated,
                    'calculated_is_top_group' => $calculated,
                    'reason' => $reason,
                    'previous_is_top_group' => $wasTopGroup,
                    'last_start_year' => $lastStartYear,
                    'last_start_points' => $starts->get($lastStartYear),
                    'prior_start_year' => $priorStartYear,
                    'prior_start_points' => $starts->get($priorStartYear),
                    'calculated_at' => $calculatedAt,
                ],
            );
        }
    }

    /**
     * @return array{bool, TopGroupReason}
     */
    private function applyRule(
        bool $isKader,
        ?bool $wasTopGroup,
        bool $startedInPreviousCup,
        ?int $lastStartPoints,
        ?int $priorStartPoints,
        int $threshold,
    ): array {
        if ($isKader) {
            return [true, TopGroupReason::Kader];
        }

        if ($startedInPreviousCup && $priorStartPoints !== null) {
            $lastAbove = $lastStartPoints > $threshold;
            $priorAbove = $priorStartPoints > $threshold;

            if ($lastAbove && $priorAbove) {
                return [true, TopGroupReason::TwoYearsAbove];
            }

            if (! $lastAbove && ! $priorAbove) {
                return [false, TopGroupReason::TwoYearsBelow];
            }
        }

        return [$wasTopGroup ?? false, TopGroupReason::CarriedOver];
    }

    /**
     * Beste Tageswertungs-Punkte je Athlet und früherem Cup-Jahr, neuestes Jahr zuerst.
     *
     * @return Collection<int, Collection<int, int>> athlete_id => (Cup-Jahr => höchste Punktezahl)
     */
    private function bestPointsPerEarlierCup(Cup $cup): Collection
    {
        return CupDailyResult::query()
            ->join('cups', 'cups.id', '=', 'cup_daily_results.cup_id')
            ->where('cups.year', '<', $cup->year)
            ->selectRaw('cup_daily_results.athlete_id, cups.year, MAX(cup_daily_results.points) as best_points')
            ->groupBy('cup_daily_results.athlete_id', 'cups.year')
            ->orderByDesc('cups.year')
            ->get()
            ->groupBy('athlete_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(
                fn (CupDailyResult $row) => [(int) $row->getAttribute('year') => (int) $row->getAttribute('best_points')]
            ));
    }

    /**
     * IDs aller Athleten, die irgendwann im Cup-Jahr dem Nationalkader angehören.
     *
     * @return Collection<int, int>
     */
    private function kaderAthleteIdsDuring(int $year): Collection
    {
        return Athlete::whereHas(
            'kaderMemberships',
            fn ($query) => $query->activeDuring(Carbon::create($year)->startOfYear(), Carbon::create($year)->endOfYear())
        )->pluck('id');
    }
}

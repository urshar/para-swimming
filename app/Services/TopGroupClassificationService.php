<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupTopGroupClassification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TopGroupClassificationService
 *
 * Bestimmt zu Saisonbeginn, welche Athleten in die Top-Gruppe eines Cup-Jahres
 * fallen (Punkt 8 der Spec, konkretisiert durch Erik):
 *
 *   - Nationalkader-Mitglieder bleiben IMMER in der Top-Gruppe (Ausnahme vom Abstieg).
 *   - Alle anderen: Auf-/Abstieg anhand der besten Tageswertung, die der
 *     Athlet in den beiden Kalenderjahren VOR dem Cup-Jahr bei einem
 *     Cup-Meet erzielt hat. Über der Punktgrenze → Top-Gruppe, sonst nicht.
 *
 * MUSS vor DailyRankingService::calculateForMeet() für dasselbe Cup-Jahr
 * laufen, da GroupResolverService::isTopGroup() auf diesen Snapshot zugreift.
 * "Ausländischer Verein" (Punkt 6) ist bewusst NICHT Teil dieser saisonalen
 * Klassifizierung — das bleibt eine sofortige, ergebnisbezogene Prüfung.
 */
readonly class TopGroupClassificationService
{
    /**
     * @return EloquentCollection<int, CupTopGroupClassification>
     *
     * @throws Throwable bei einem Fehler innerhalb der Transaktion
     */
    public function calculateForCup(Cup $cup): EloquentCollection
    {
        DB::transaction(function () use ($cup) {
            CupTopGroupClassification::where('cup_id', $cup->id)->delete();

            $bestPointsByAthlete = $this->bestPointsFromPreviousTwoYears($cup);
            $kaderAthleteIds = $this->currentlyActiveKaderAthleteIds();

            $athleteIds = $bestPointsByAthlete->keys()->merge($kaderAthleteIds)->unique();

            $calculatedAt = now();

            foreach ($athleteIds as $athleteId) {
                $isKader = $kaderAthleteIds->contains($athleteId);
                $referencePoints = $bestPointsByAthlete->get($athleteId);
                $exceedsThreshold = $referencePoints !== null && $referencePoints > $cup->top_group_points_threshold;

                CupTopGroupClassification::create([
                    'cup_id' => $cup->id,
                    'athlete_id' => $athleteId,
                    'is_top_group' => $isKader || $exceedsThreshold,
                    'reason' => $isKader ? 'KADER' : ($exceedsThreshold ? 'POINTS_HISTORY' : null),
                    'reference_points' => $referencePoints,
                    'calculated_at' => $calculatedAt,
                ]);
            }
        });

        return CupTopGroupClassification::where('cup_id', $cup->id)->get();
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

    /**
     * Beste Tageswertungs-Punkte je Athlet aus den Cups der beiden
     * Kalenderjahre vor $cup->year (falls für diese Jahre überhaupt Cups
     * existieren und bereits eine Tageswertung berechnet wurde).
     *
     * @return Collection<int, int> athlete_id => höchste erreichte Punktezahl
     */
    private function bestPointsFromPreviousTwoYears(Cup $cup): Collection
    {
        $previousCupIds = Cup::whereIn('year', [$cup->year - 1, $cup->year - 2])->pluck('id');

        if ($previousCupIds->isEmpty()) {
            return collect();
        }

        return CupDailyResult::whereIn('cup_id', $previousCupIds)
            ->selectRaw('athlete_id, MAX(points) as best_points')
            ->groupBy('athlete_id')
            ->get()
            ->mapWithKeys(fn (CupDailyResult $row) => [$row->athlete_id => (int) $row->best_points]);
    }

    /**
     * IDs aller Athleten mit aktuell (Stichtag: Berechnungszeitpunkt) gültiger
     * Nationalkader-Zugehörigkeit.
     *
     * @return Collection<int, int>
     */
    private function currentlyActiveKaderAthleteIds(): Collection
    {
        return Athlete::whereHas(
            'kaderMemberships',
            fn ($query) => $query->activeOn(now())
        )->pluck('id');
    }
}

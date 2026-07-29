<?php

namespace App\Services;

use App\Models\AthleteKaderMembership;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupOverallResult;
use App\Models\CupTopGroupClassification;
use App\Models\Meet;
use App\Models\Result;
use Carbon\Carbon;

/**
 * CupStalenessService
 *
 * Die Cup-Wertung besteht aus einer Berechnungskette:
 *   Kaderzugehörigkeit → Top-Gruppen-Klassifizierung → Tageswertung → Gesamtwertung
 *
 * Jede Stufe ist ein eigener, manuell ausgelöster Snapshot (Erik: "Snapshot je
 * Berechnungslauf"). Ändert sich eine vorgelagerte Stufe NACH der letzten
 * Berechnung einer nachgelagerten Stufe, spiegelt Letztere diese Änderung
 * nicht mehr wider — ohne Hinweis fällt das nur auf, wenn man es wie in der
 * Praxis manuell nachrechnet (siehe Debugging-Session zur Top-Gruppe).
 *
 * Dieser Service prüft ausschließlich Zeitstempel und löst nichts automatisch
 * aus — die Neuberechnung bleibt bewusst ein manueller, admin-only Schritt.
 */
readonly class CupStalenessService
{
    /**
     * @return array{calculatedAt: ?Carbon, isStale: bool, reason: ?string}
     */
    public function topGroupClassificationStatus(Cup $cup): array
    {
        $calculatedAt = CupTopGroupClassification::where('cup_id', $cup->id)->max('calculated_at');

        if (! $calculatedAt) {
            return ['calculatedAt' => null, 'isStale' => false, 'reason' => null];
        }

        $calculatedAt = Carbon::parse($calculatedAt);
        $latestKaderChange = AthleteKaderMembership::max('updated_at');

        $isStale = $latestKaderChange !== null && Carbon::parse($latestKaderChange)->gt($calculatedAt);

        return [
            'calculatedAt' => $calculatedAt,
            'isStale' => $isStale,
            'reason' => $isStale ? 'Kaderzugehörigkeiten wurden seither geändert.' : null,
        ];
    }

    /**
     * @return array{calculatedAt: ?Carbon, isStale: bool, reason: ?string}
     */
    public function dailyRankingStatus(Meet $meet): array
    {
        $calculatedAt = CupDailyResult::where('meet_id', $meet->id)->max('calculated_at');

        if (! $calculatedAt || ! $meet->cup_id) {
            return [
                'calculatedAt' => $calculatedAt ? Carbon::parse($calculatedAt) : null, 'isStale' => false,
                'reason' => null,
            ];
        }

        $calculatedAt = Carbon::parse($calculatedAt);

        $reasons = [];

        $classificationCalculatedAt = CupTopGroupClassification::where('cup_id', $meet->cup_id)->max('calculated_at');
        if ($classificationCalculatedAt !== null && Carbon::parse($classificationCalculatedAt)->gt($calculatedAt)) {
            $reasons[] = 'die Top-Gruppen-Klassifizierung wurde seither aktualisiert';
        }

        $latestResultChange = Result::where('meet_id', $meet->id)->max('updated_at');
        if ($latestResultChange !== null && Carbon::parse($latestResultChange)->gt($calculatedAt)) {
            $reasons[] = 'Ergebnisse dieses Meets wurden seither geändert';
        }

        return [
            'calculatedAt' => $calculatedAt,
            'isStale' => $reasons !== [],
            'reason' => $reasons !== [] ? 'Veraltet, da '.implode(' und ', $reasons).'.' : null,
        ];
    }

    /**
     * @return array{calculatedAt: ?Carbon, isStale: bool, reason: ?string}
     */
    public function overallRankingStatus(Cup $cup): array
    {
        $calculatedAt = CupOverallResult::where('cup_id', $cup->id)->max('calculated_at');

        if (! $calculatedAt) {
            return ['calculatedAt' => null, 'isStale' => false, 'reason' => null];
        }

        $calculatedAt = Carbon::parse($calculatedAt);
        $meetIds = Meet::where('cup_id', $cup->id)->pluck('id');

        $reasons = [];

        $latestDailyCalculatedAt = CupDailyResult::whereIn('meet_id', $meetIds)->max('calculated_at');
        if ($latestDailyCalculatedAt !== null && Carbon::parse($latestDailyCalculatedAt)->gt($calculatedAt)) {
            $reasons[] = 'die Tageswertung wurde seither neu berechnet';
        }

        $classificationCalculatedAt = CupTopGroupClassification::where('cup_id', $cup->id)->max('calculated_at');
        if ($classificationCalculatedAt !== null && Carbon::parse($classificationCalculatedAt)->gt($calculatedAt)) {
            $reasons[] = 'die Top-Gruppen-Klassifizierung wurde seither aktualisiert';
        }

        return [
            'calculatedAt' => $calculatedAt,
            'isStale' => $reasons !== [],
            'reason' => $reasons !== [] ? 'Veraltet, da '.implode(' und ', $reasons).'.' : null,
        ];
    }

    /**
     * Aktualität der leistungsorientierten Vereinswertung (Wertungssystem B).
     *
     * Diese Wertung wird nicht persistiert, sondern dynamisch aus den
     * Tageswertungen (cup_daily_results) ALLER Cup-Meets berechnet. Sie ist
     * daher "veraltet", wenn (a) für ein Cup-Meet mit Ergebnissen (noch) keine
     * Tageswertung vorliegt — dann fehlt dieses Meet in der Wertung — oder (b)
     * die Tageswertung eines Cup-Meets laut dailyRankingStatus() veraltet ist.
     * calculatedAt ist die jüngste Tageswertungs-Berechnung über alle Cup-Meets.
     *
     * @return array{calculatedAt: ?Carbon, isStale: bool, reason: ?string}
     */
    public function clubRankingStatus(Cup $cup): array
    {
        $meets = Meet::where('cup_id', $cup->id)->get(['id', 'cup_id']);

        if ($meets->isEmpty()) {
            return ['calculatedAt' => null, 'isStale' => false, 'reason' => null];
        }

        $latestDailyCalculatedAt = CupDailyResult::whereIn('meet_id', $meets->pluck('id'))->max('calculated_at');
        $calculatedAt = $latestDailyCalculatedAt ? Carbon::parse($latestDailyCalculatedAt) : null;

        $missingCount = 0;
        $staleCount = 0;

        foreach ($meets as $meet) {
            $hasDaily = CupDailyResult::where('meet_id', $meet->id)->exists();

            if (! $hasDaily) {
                if (Result::where('meet_id', $meet->id)->exists()) {
                    $missingCount++;
                }

                continue;
            }

            if ($this->dailyRankingStatus($meet)['isStale']) {
                $staleCount++;
            }
        }

        $reasons = [];
        if ($missingCount > 0) {
            $reasons[] = "für $missingCount Meet(s) liegt keine Tageswertung vor";
        }
        if ($staleCount > 0) {
            $reasons[] = "die Tageswertung von $staleCount Meet(s) ist veraltet";
        }

        return [
            'calculatedAt' => $calculatedAt,
            'isStale' => $reasons !== [],
            'reason' => $reasons !== [] ? 'Veraltet, da '.implode(' und ', $reasons).'.' : null,
        ];
    }
}

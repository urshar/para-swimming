<?php

use App\Models\Athlete;
use App\Models\Result;
use App\Models\WpsScmConversionFactor;
use App\Services\WpsPointCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('wps-points-p6');

// Helper aus tests/helpers_wps2.php.

/**
 * Legt weitere Ergebnisse im selben Bewerb an, damit die Abfragezahl bei wachsender
 * Ergebnismenge geprüft werden kann.
 */
function extraResults_wps6(Result $vorlage, int $anzahl): void
{
    for ($i = 0; $i < $anzahl; $i++) {
        $athlet = Athlete::create([
            'first_name' => 'Test'.$i,
            'last_name' => 'Athlet',
            'gender' => 'M',
            'birth_date' => '2005-06-01',
            'club_id' => $vorlage->club_id,
            'nation_id' => $vorlage->club->nation_id,
        ]);

        Result::create([
            'meet_id' => $vorlage->meet_id,
            'swim_event_id' => $vorlage->swim_event_id,
            'athlete_id' => $athlet->id,
            'club_id' => $vorlage->club_id,
            'swim_time' => 5700 + $i,
            'sport_class' => 'S2',
        ]);
    }
}

describe('Abfrageverhalten der Massenberechnung', function () {
    it('lädt die Parametertabelle einmal statt je Ergebnis', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        $result = result_wps2(result: ['swim_time' => 5700, 'sport_class' => 'S2']);
        extraResults_wps6($result, 19);

        DB::enableQueryLog();
        $summary = app(WpsPointCalculationService::class)->recalculateForMeet($result->meet);
        $abfragen = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 20 Ergebnisse. Ohne Vorladen wäre allein die Parametersuche 20 Abfragen; dazu je
        // Ergebnis ein UPDATE. Die Schranke ist bewusst grob — sie soll einen Rückfall in
        // das N+1-Verhalten erkennen, nicht eine exakte Zahl festschreiben.
        expect($summary['updated'])->toBe(20)
            ->and($abfragen)->toBeLessThan(35);
    });

    it('wächst nicht überproportional mit der Ergebnismenge', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        $result = result_wps2(result: ['swim_time' => 5700, 'sport_class' => 'S2']);

        DB::enableQueryLog();
        app(WpsPointCalculationService::class)->recalculateForMeet($result->meet);
        $beiEinem = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Das Anlegen der zusätzlichen Ergebnisse läuft bewusst OHNE Mitschnitt: Die
        // INSERTs des Testaufbaus gehören nicht zur gemessenen Berechnung.
        extraResults_wps6($result, 19);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(WpsPointCalculationService::class)->recalculateForMeet($result->meet->fresh());
        $beiZwanzig = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Der Zuwachs darf nur aus den UPDATEs stammen: 19 zusätzliche Ergebnisse, also
        // höchstens 19 zusätzliche Abfragen plus etwas Spielraum.
        expect($beiZwanzig - $beiEinem)->toBeLessThanOrEqual(21);
    });

    it('lädt die Umrechnungsfaktoren einmal je Berechnungslauf', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        WpsScmConversionFactor::create([
            'stroke_type_id' => stroke_wps2('FREE')->id,
            'distance' => null, 'sport_class' => null, 'gender' => null,
            'factor' => 1.02,
            'source' => WpsScmConversionFactor::SOURCE_LITERATURE,
            'confidence_level' => WpsScmConversionFactor::CONFIDENCE_MEDIUM,
            'active' => true,
        ]);

        $result = result_wps2(result: ['swim_time' => 5700, 'sport_class' => 'S2'], course: 'SCM');
        extraResults_wps6($result, 9);

        DB::enableQueryLog();
        $summary = app(WpsPointCalculationService::class)->recalculateForMeet($result->meet);
        $abfragen = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Auf der Kurzbahn kämen ohne Vorladen zwei Parameterabfragen je Ergebnis dazu
        // (erst SCM, dann LCM) plus eine Faktorabfrage.
        expect($summary['updated'])->toBe(10)
            ->and($abfragen)->toBeLessThan(25);
    });

    it('lädt die Beziehungen der Ergebnisse vorab', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        $result = result_wps2(result: ['swim_time' => 5700, 'sport_class' => 'S2']);
        extraResults_wps6($result, 9);

        DB::enableQueryLog();
        app(WpsPointCalculationService::class)->recalculateForMeet($result->meet);
        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        // Athlet, Bewerb, Schwimmstil und Veranstaltung dürfen nicht je Ergebnis einzeln
        // nachgeladen werden.
        expect($log->filter(fn (string $q): bool => str_contains($q, 'from "athletes"'))->count())
            ->toBeLessThanOrEqual(2);
    });
});

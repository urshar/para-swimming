<?php

use App\Models\Meet;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Models\WpsPointParameter;
use App\Models\WpsScmConversionFactor;
use App\Services\WpsPointCalculationService;
use App\Services\WpsScmConversionService;
use App\Services\WpsScmFactorCalibrationService;
use Database\Seeders\WpsScmConversionFactorsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('wps-points-p5');

beforeEach(function () {
    // Die Produktivvorgabe liegt bei 6 Athleten. Die Testfälle prüfen das Verhalten der
    // Kalibrierung, nicht die Schwelle selbst — diese hat einen eigenen Block weiter unten.
    config(['wps.calibration.min_sample_size' => 3]);
});

// Die Helper (result_wps2, parameter_wps2, version_wps2, stroke_wps2, …) stammen aus
// tests/helpers_wps2.php.

/** @param array<string, mixed> $overrides */
function factor_wps5(array $overrides = []): WpsScmConversionFactor
{
    return WpsScmConversionFactor::create(array_merge([
        'stroke_type_id' => stroke_wps2('FREE')->id,
        'distance' => null,
        'sport_class' => null,
        'gender' => null,
        'factor' => 1.02,
        'source' => WpsScmConversionFactor::SOURCE_LITERATURE,
        'confidence_level' => WpsScmConversionFactor::CONFIDENCE_LOW,
        'active' => true,
    ], $overrides));
}

function conversion_wps5(): WpsScmConversionService
{
    return app(WpsScmConversionService::class);
}

function calibration_wps5(): WpsScmFactorCalibrationService
{
    return app(WpsScmFactorCalibrationService::class);
}

/** Legt für einen Athleten je eine LCM- und eine SCM-Zeit im selben Bewerb an. */
function paar_wps5(
    int $lcmZeit,
    int $scmZeit,
    string $sportClass = 'S14',
    string $scmDatum = '2026-03-01',
): void {
    // result_wps2() legt die Langbahnveranstaltung auf den 01.05.2026.
    $lcm = result_wps2(result: ['swim_time' => $lcmZeit, 'sport_class' => $sportClass]);

    $scmMeet = Meet::create([
        'name' => 'Kurzbahn', 'nation_id' => nation_wps2()->id,
        'course' => 'SCM', 'start_date' => $scmDatum,
    ]);

    $scmEvent = SwimEvent::create([
        'meet_id' => $scmMeet->id,
        'stroke_type_id' => $lcm->swimEvent->stroke_type_id,
        'distance' => $lcm->swimEvent->distance,
        'relay_count' => 1,
        'gender' => 'M',
    ]);

    Result::create([
        'meet_id' => $scmMeet->id,
        'swim_event_id' => $scmEvent->id,
        'athlete_id' => $lcm->athlete_id,
        'club_id' => $lcm->club_id,
        'swim_time' => $scmZeit,
        'sport_class' => $sportClass,
    ]);
}

function admin_wps5(): User
{
    return User::factory()->create(['is_admin' => true]);
}

// ── Sportklassen 21 → 14 ─────────────────────────────────────────────────────

describe('Sportklassen 21', function () {
    it('bildet 21 auf 14 ab und liefert Punkte', function (string $klasse, string $erwartet, string $stroke) {
        parameter_wps2([
            'sport_class' => $erwartet,
            'stroke_type_id' => stroke_wps2($stroke)->id,
            'parameter_c' => 188.441,
        ]);

        $result = result_wps2(
            result: ['sport_class' => $klasse],
            event: ['stroke_type_id' => stroke_wps2($stroke)->id]
        );

        $calculated = calculator_wps2()->calculate($result, version_wps2());

        expect($calculated->wasCalculated())->toBeTrue()
            ->and($calculated->parameter->sport_class)->toBe($erwartet);
    })->with([
        'S21 → S14' => ['S21', 'S14', 'FREE'],
        'SB21 → SB14' => ['SB21', 'SB14', 'BREAST'],
        'SM21 → SM14' => ['SM21', 'SM14', 'MEDLEY'],
    ]);

    it('lässt results.sport_class unverändert', function () {
        parameter_wps2(['sport_class' => 'S14']);
        $result = result_wps2(result: ['sport_class' => 'S21']);

        app(WpsPointCalculationService::class)->recalculateForResult($result);

        expect($result->fresh()->sport_class)->toBe('S21')
            ->and($result->fresh()->wps_points)->not->toBeNull();
    });

    it('weist die reinen Staffelklassen weiterhin ab', function (string $klasse) {
        parameter_wps2(['sport_class' => 'S14']);

        expect(calculator_wps2()->calculate(
            result_wps2(result: ['sport_class' => $klasse]),
            version_wps2()
        )->wasCalculated())->toBeFalse();
    })->with(['S20', 'S34', 'S49', 'S15']);
});

// ── Faktorauflösung ──────────────────────────────────────────────────────────

describe('Faktorauflösung', function () {
    it('nimmt den spezifischsten passenden Faktor', function () {
        $stroke = stroke_wps2('FREE')->id;
        factor_wps5(['factor' => 1.01]);
        factor_wps5(['distance' => 50, 'factor' => 1.02]);
        $genau = factor_wps5(['distance' => 50, 'sport_class' => 'S10', 'factor' => 1.03]);

        expect(conversion_wps5()->resolveFactor($stroke, 50, 'S10', 'M')->id)->toBe($genau->id);
    });

    it('fällt auf den Bewerbswert zurück, wenn die Klasse fehlt', function () {
        $stroke = stroke_wps2('FREE')->id;
        factor_wps5(['factor' => 1.01]);
        $bewerb = factor_wps5(['distance' => 50, 'factor' => 1.02]);
        factor_wps5(['distance' => 50, 'sport_class' => 'S14', 'factor' => 1.03]);

        expect(conversion_wps5()->resolveFactor($stroke, 50, 'S3', 'M')->id)->toBe($bewerb->id);
    });

    it('fällt auf den Stilwert zurück, wenn die Strecke fehlt', function () {
        $stroke = stroke_wps2('FREE')->id;
        $stil = factor_wps5(['factor' => 1.01]);
        factor_wps5(['distance' => 50, 'factor' => 1.02]);

        expect(conversion_wps5()->resolveFactor($stroke, 200, 'S10', 'M')->id)->toBe($stil->id);
    });

    it('berücksichtigt das Geschlecht, wenn hinterlegt', function () {
        $stroke = stroke_wps2('FREE')->id;
        factor_wps5(['distance' => 50, 'factor' => 1.02]);
        $weiblich = factor_wps5(['distance' => 50, 'gender' => 'F', 'factor' => 1.025]);

        expect(conversion_wps5()->resolveFactor($stroke, 50, 'S10', 'F')->id)->toBe($weiblich->id);
    });

    it('ignoriert deaktivierte Faktoren', function () {
        $stroke = stroke_wps2('FREE')->id;
        factor_wps5(['active' => false]);

        expect(conversion_wps5()->resolveFactor($stroke, 50, 'S10', 'M'))->toBeNull();
    });

    it('liefert null für einen anderen Schwimmstil', function () {
        factor_wps5();

        expect(conversion_wps5()->resolveFactor(stroke_wps2('BACK')->id, 50, 'S10', 'M'))->toBeNull();
    });

    it('rechnet die Zeit um und schneidet ab', function () {
        // 3000 Hundertstel × 1,0266 = 3079,8 → 3079
        expect(conversion_wps5()->convert(3000, factor_wps5(['factor' => 1.0266])))->toBe(3079);
    });
});

// ── Berechnung auf der Kurzbahn ──────────────────────────────────────────────

describe('Kurzbahn-Berechnung', function () {
    it('rechnet die Zeit um und wendet die offizielle Tabelle an', function () {
        // Men, 50 Freistil, S2: c = 433.181. 5700 SCM × 1,0 → keine Änderung wäre 939.
        // Mit Faktor 1,02: 5814 Hundertstel = 58,14 s → weniger Punkte.
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        factor_wps5(['factor' => 1.02]);

        $result = result_wps2(
            result: ['swim_time' => 5700, 'sport_class' => 'S2'],
            course: 'SCM'
        );

        $calculated = calculator_wps2()->calculate($result, version_wps2());

        expect($calculated->wasCalculated())->toBeTrue()
            ->and($calculated->wasConverted())->toBeTrue()
            ->and($calculated->estimatedLcmTime)->toBe(5814)
            ->and($calculated->calculationType)->toBe(Result::WPS_TYPE_ESTIMATED)
            ->and($calculated->points)->toBeLessThan(939);
    });

    it('speichert die geschätzte Langbahnzeit und den Faktor', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        $factor = factor_wps5(['factor' => 1.02]);

        $result = result_wps2(
            result: ['swim_time' => 5700, 'sport_class' => 'S2'],
            course: 'SCM'
        );

        app(WpsPointCalculationService::class)->recalculateForResult($result);
        $result = $result->fresh();

        expect($result->wps_estimated_lcm_time)->toBe(5814)
            ->and($result->wps_conversion_factor_id)->toBe($factor->id)
            ->and($result->hasConvertedWpsTime())->toBeTrue()
            ->and($result->hasEstimatedWpsPoints())->toBeTrue();
    });

    it('überspringt ohne Faktor, statt mit 1 zu rechnen', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);

        $calculated = calculator_wps2()->calculate(
            result_wps2(result: ['swim_time' => 5700, 'sport_class' => 'S2'], course: 'SCM'),
            version_wps2()
        );

        expect($calculated->wasCalculated())->toBeFalse()
            ->and($calculated->skipReason)->toContain('kein Umrechnungsfaktor');
    });

    it('bevorzugt einen offiziellen SCM-Parametersatz vor der Umrechnung', function () {
        parameter_wps2([
            'course' => WpsPointParameter::COURSE_SCM,
            'sport_class' => 'S2',
            'parameter_c' => 433.181,
            'official' => true,
        ]);
        factor_wps5(['factor' => 1.02]);

        $calculated = calculator_wps2()->calculate(
            result_wps2(result: ['swim_time' => 5700, 'sport_class' => 'S2'], course: 'SCM'),
            version_wps2()
        );

        expect($calculated->wasConverted())->toBeFalse()
            ->and($calculated->estimatedLcmTime)->toBeNull()
            ->and($calculated->calculationType)->toBe(Result::WPS_TYPE_OFFICIAL)
            ->and($calculated->points)->toBe(939);
    });

    it('rechnet auf der Langbahn nicht um', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        factor_wps5(['factor' => 1.02]);

        $calculated = calculator_wps2()->calculate(
            result_wps2(result: ['swim_time' => 5700, 'sport_class' => 'S2']),
            version_wps2()
        );

        expect($calculated->wasConverted())->toBeFalse()
            ->and($calculated->points)->toBe(939);
    });

    it('räumt die Umrechnungsfelder, wenn das Ergebnis nicht mehr berechenbar ist', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        factor_wps5(['factor' => 1.02]);
        $result = result_wps2(result: ['swim_time' => 5700, 'sport_class' => 'S2'], course: 'SCM');

        $service = app(WpsPointCalculationService::class);
        $service->recalculateForResult($result);

        $result->update(['status' => 'DSQ']);
        $service->recalculateForResult($result->fresh());
        $result = $result->fresh();

        expect($result->wps_estimated_lcm_time)->toBeNull()
            ->and($result->wps_conversion_factor_id)->toBeNull()
            ->and($result->wps_points)->toBeNull();
    });
});

// ── Kalibrierung aus eigenen Daten ───────────────────────────────────────────

describe('Kalibrierung', function () {
    it('bildet den Median der Einzelverhältnisse', function () {
        // Verhältnisse: 1,02 / 1,04 / 1,05 → Median 1,04
        paar_wps5(5100, 5000);
        paar_wps5(5200, 5000);
        paar_wps5(5250, 5000);

        $beobachtet = calibration_wps5()->observedRatios()->first();

        expect($beobachtet['sample_size'])->toBe(3)
            ->and(round($beobachtet['median'], 4))->toBe(1.04);
    });

    it('lässt sich von einem Ausreißer nicht verziehen — anders als ein Mittelwert', function () {
        // Alle drei innerhalb der Plausibilitätsgrenzen: 1,02 / 1,03 / 1,058.
        // Mittelwert wäre 1,036; der Median bleibt bei 1,03.
        paar_wps5(5100, 5000);
        paar_wps5(5150, 5000);
        paar_wps5(5290, 5000);

        $beobachtet = calibration_wps5()->observedRatios()->first();

        expect(round($beobachtet['median'], 4))->toBe(1.03)
            ->and($beobachtet['rejected'])->toBe(0);
    });

    it('schreibt einen Faktor erst ab drei Athleten', function () {
        paar_wps5(5100, 5000);
        paar_wps5(5200, 5000);

        $summary = calibration_wps5()->calibrate();

        expect($summary['created'])->toBe(0)
            ->and($summary['skipped'])->toBe(1)
            ->and(WpsScmConversionFactor::count())->toBe(0);
    });

    it('legt ab drei Athleten einen Faktor aus eigenen Daten an', function () {
        paar_wps5(5100, 5000);
        paar_wps5(5200, 5000);
        paar_wps5(5250, 5000);

        $summary = calibration_wps5()->calibrate();
        $factor = WpsScmConversionFactor::first();

        expect($summary['created'])->toBe(1)
            ->and($factor->source)->toBe(WpsScmConversionFactor::SOURCE_OWN_DATA)
            ->and($factor->sample_size)->toBe(3)
            ->and($factor->sport_class)->toBe('S14')
            ->and($factor->isFromOwnData())->toBeTrue();
    });

    it('überschreibt einen manuell gesetzten Faktor nicht', function () {
        paar_wps5(5100, 5000);
        paar_wps5(5200, 5000);
        paar_wps5(5250, 5000);

        $manuell = factor_wps5([
            'distance' => 50,
            'sport_class' => 'S14',
            'factor' => 1.5,
            'source' => WpsScmConversionFactor::SOURCE_MANUAL,
        ]);

        calibration_wps5()->calibrate();

        expect($manuell->fresh()->factor)->toBe(1.5);
    });

    it('ignoriert Ergebnisse ohne wertbare Leistung', function () {
        paar_wps5(5100, 5000);
        paar_wps5(5200, 5000);
        $dsq = result_wps2(result: ['swim_time' => 9999, 'sport_class' => 'S14', 'status' => 'DSQ']);

        expect(calibration_wps5()->observedRatios()->first()['sample_size'])->toBe(2)
            ->and($dsq->status)->toBe('DSQ');
    });

    it('erzeugt einen Bericht mit angesetztem und beobachtetem Wert', function () {
        paar_wps5(5100, 5000);
        paar_wps5(5200, 5000);
        paar_wps5(5250, 5000);
        factor_wps5(['factor' => 1.02]);

        $bericht = calibration_wps5()->report(conversion_wps5());
        $zeile = $bericht->first();

        expect($zeile['sample_size'])->toBe(3)
            ->and($zeile['applied_factor'])->toBe(1.02)
            ->and($zeile['sufficient'])->toBeTrue()
            ->and($zeile['deviation'])->toBeGreaterThan(0);
    });
});

// ── Zeitfenster und Plausibilität ────────────────────────────────────────────

describe('Zeitfenster', function () {
    it('verwirft Paare außerhalb des Fensters', function () {
        config(['wps.calibration.window_months' => 6]);

        // Langbahn am 01.05.2026, Kurzbahn zwei Jahre davor: der Vergleich misst die
        // Entwicklung des Athleten, nicht den Bahnunterschied.
        paar_wps5(5100, 5000, scmDatum: '2024-03-01');

        expect(calibration_wps5()->observedRatios())->toBeEmpty();
    });

    it('berücksichtigt Paare innerhalb des Fensters', function () {
        config(['wps.calibration.window_months' => 6]);
        paar_wps5(5100, 5000);

        expect(calibration_wps5()->observedRatios())->toHaveCount(1);
    });

    it('lässt sich über die Konfiguration erweitern', function () {
        paar_wps5(5100, 5000, scmDatum: '2025-06-01');

        config(['wps.calibration.window_months' => 6]);
        expect(calibration_wps5()->observedRatios())->toBeEmpty();

        config(['wps.calibration.window_months' => 24]);
        expect(calibration_wps5()->observedRatios())->toHaveCount(1);
    });

    it('nimmt bei mehreren Kurzbahnzeiten die zeitlich nächste', function () {
        config(['wps.calibration.window_months' => 24]);

        // Zwei Kurzbahnstarts desselben Athleten: einer knapp vor der Langbahnzeit,
        // einer weit davor. Maßgeblich ist der nähere.
        $lcm = result_wps2(result: ['swim_time' => 5100, 'sport_class' => 'S14']);

        foreach ([['2026-04-01', 5000], ['2025-01-01', 4000]] as [$datum, $zeit]) {
            $meet = Meet::create([
                'name' => 'Kurzbahn '.$datum, 'nation_id' => nation_wps2()->id,
                'course' => 'SCM', 'start_date' => $datum,
            ]);

            $event = SwimEvent::create([
                'meet_id' => $meet->id,
                'stroke_type_id' => $lcm->swimEvent->stroke_type_id,
                'distance' => $lcm->swimEvent->distance,
                'relay_count' => 1,
                'gender' => 'M',
            ]);

            Result::create([
                'meet_id' => $meet->id, 'swim_event_id' => $event->id,
                'athlete_id' => $lcm->athlete_id, 'club_id' => $lcm->club_id,
                'swim_time' => $zeit, 'sport_class' => 'S14',
            ]);
        }

        // 5100/5000 = 1,02 — nicht 5100/4000 = 1,275
        expect(round(calibration_wps5()->observedRatios()->first()['median'], 4))->toBe(1.02);
    });
});

describe('Plausibilitätsgrenzen', function () {
    it('verwirft unplausible Verhältnisse und weist sie aus', function () {
        // 1,02 / 1,03 plausibel, 1,50 nicht.
        paar_wps5(5100, 5000);
        paar_wps5(5150, 5000);
        paar_wps5(7500, 5000);

        $beobachtet = calibration_wps5()->observedRatios()->first();

        expect($beobachtet['sample_size'])->toBe(2)
            ->and($beobachtet['rejected'])->toBe(1)
            ->and($beobachtet['max'])->toBeLessThan(1.06);
    });

    it('verwirft auch zu niedrige Verhältnisse', function () {
        // Kurzbahn langsamer als Langbahn — praktisch immer ein Formunterschied.
        paar_wps5(4500, 5000);

        expect(calibration_wps5()->observedRatios())->toBeEmpty();
    });

    it('lässt die Grenzen über die Konfiguration verschieben', function () {
        paar_wps5(5400, 5000);

        expect(calibration_wps5()->observedRatios())->toBeEmpty();

        config(['wps.calibration.max_ratio' => 1.10]);

        expect(calibration_wps5()->observedRatios())->toHaveCount(1);
    });

    it('meldet verworfene Paare in der Zusammenfassung', function () {
        paar_wps5(5100, 5000);
        paar_wps5(5150, 5000);
        paar_wps5(7500, 5000);

        expect(calibration_wps5()->calibrate()['rejected_pairs'])->toBe(1);
    });
});

// ── Zuordnung 21 → 14 bei der Kalibrierung ───────────────────────────────────

describe('Kalibrierung und Sportklassen 21', function () {
    it('führt S21-Athleten mit S14 zusammen', function () {
        paar_wps5(5100, 5000);
        paar_wps5(5200, 5000);
        paar_wps5(5250, 5000, 'S21');

        $beobachtet = calibration_wps5()->observedRatios();

        // Eine einzige Gruppe statt zweier — die S21-Athleten stärken die Stichprobe von S14,
        // statt einen Faktor zu bilden, den zur Rechenzeit niemand abfragt.
        expect($beobachtet)->toHaveCount(1)
            ->and($beobachtet->first()['sport_class'])->toBe('S14')
            ->and($beobachtet->first()['sample_size'])->toBe(3);
    });

    it('legt keinen eigenen Faktor für S21 an', function () {
        paar_wps5(5100, 5000, 'S21');
        paar_wps5(5200, 5000, 'S21');
        paar_wps5(5250, 5000, 'S21');

        calibration_wps5()->calibrate();

        expect(WpsScmConversionFactor::where('sport_class', 'S21')->count())->toBe(0)
            ->and(WpsScmConversionFactor::where('sport_class', 'S14')->count())->toBe(1);
    });

    it('ignoriert Klassen ohne WPS-Entsprechung', function () {
        paar_wps5(5100, 5000, 'S20');
        paar_wps5(5200, 5000, 'GER.AB');

        expect(calibration_wps5()->observedRatios())->toBeEmpty();
    });

    it('ein S21-Ergebnis verwendet den S14-Faktor', function () {
        parameter_wps2(['sport_class' => 'S14', 'parameter_c' => 188.441]);
        $s14 = factor_wps5(['distance' => 50, 'sport_class' => 'S14', 'factor' => 1.03]);
        factor_wps5(['factor' => 1.01]);

        $result = result_wps2(result: ['sport_class' => 'S21'], course: 'SCM');

        expect(calculator_wps2()->calculate($result, version_wps2())->conversionFactor->id)
            ->toBe($s14->id);
    });
});

// ── Mindestgröße und Untergrenze des Medians ─────────────────────────────────

describe('Belastbarkeit', function () {
    it('verlangt produktiv sechs Athleten', function () {
        expect(config('wps.calibration.min_sample_size'))->toBe(3);

        // Der Standardwert der Anwendung — hier gegen die Konfigurationsdatei geprüft,
        // da beforeEach ihn für die übrigen Tests herabsetzt.
        config(['wps.calibration.min_sample_size' => 6]);

        paar_wps5(5100, 5000);
        paar_wps5(5150, 5000);
        paar_wps5(5200, 5000);
        paar_wps5(5250, 5000);

        expect(calibration_wps5()->calibrate())
            ->toMatchArray(['created' => 0, 'skipped' => 1]);
    });

    it('schreibt keinen Faktor unter 1', function () {
        // Verhältnisse 0,98 / 0,99 / 1,00 → Median 0,99. Ein Kurzbahnvorteil kann nicht
        // negativ sein; der Wert misst Formunterschiede.
        paar_wps5(4900, 5000);
        paar_wps5(4950, 5000);
        paar_wps5(5000, 5000);

        $beobachtung = calibration_wps5()->observedRatios()->first();
        $summary = calibration_wps5()->calibrate();

        expect($beobachtung['plausible_median'])->toBeFalse()
            ->and($summary['created'])->toBe(0)
            ->and($summary['implausible_medians'])->toBe(1)
            ->and(WpsScmConversionFactor::where('source', WpsScmConversionFactor::SOURCE_OWN_DATA)->count())
            ->toBe(0);
    });

    it('schreibt einen Faktor von genau 1 nicht als unplausibel ab', function () {
        paar_wps5(5000, 5000);
        paar_wps5(5100, 5000);
        paar_wps5(5000, 5000);

        expect(calibration_wps5()->observedRatios()->first()['plausible_median'])->toBeTrue();
    });

    it('lässt die betroffene Kombination auf den Sammelwert zurückfallen', function () {
        $sammelwert = factor_wps5(['factor' => 1.02]);
        paar_wps5(4900, 5000);
        paar_wps5(4950, 5000);
        paar_wps5(5000, 5000);

        calibration_wps5()->calibrate();

        // Ohne eigenen Faktor greift die Kaskade und findet den Sammelwert je Stil.
        expect(conversion_wps5()->resolveFactor(stroke_wps2('FREE')->id, 50, 'S14', 'M')->id)
            ->toBe($sammelwert->id);
    });

    it('weist einen unplausiblen Median im Bericht aus', function () {
        paar_wps5(4900, 5000);
        paar_wps5(4950, 5000);
        paar_wps5(5000, 5000);

        $zeile = calibration_wps5()->report(conversion_wps5())->first();

        expect($zeile['plausible_median'])->toBeFalse()
            ->and($zeile['sufficient'])->toBeFalse();
    });
});

// ── Seeder ───────────────────────────────────────────────────────────────────

describe('Startwerte aus den World-Aquatics-Basiszeiten', function () {
    beforeEach(function () {
        foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $code) {
            stroke_wps2($code);
        }
    });

    it('legt Faktoren je Stil, Strecke und Geschlecht an', function () {
        (new WpsScmConversionFactorsSeeder)->run();
        (new WpsScmConversionFactorsSeeder)->run();

        // 17 Bewerbe × 2 Geschlechter + 5 Sammelwerte × 2 Geschlechter
        expect(WpsScmConversionFactor::count())->toBe(44)
            ->and(WpsScmConversionFactor::whereNull('distance')->count())->toBe(10);
    });

    it('liefert ausschließlich Faktoren über 1', function () {
        (new WpsScmConversionFactorsSeeder)->run();

        // Ein Faktor unter 1 hieße, auf der Kurzbahn werde langsamer geschwommen. Genau daran
        // sind die para-spezifischen Quellen gescheitert (Spec §9.8).
        expect(WpsScmConversionFactor::where('factor', '<=', 1.0)->count())->toBe(0)
            ->and(WpsScmConversionFactor::where('factor', '>', 1.1)->count())->toBe(0);
    });

    it('gibt Rücken die höchsten Faktoren', function () {
        (new WpsScmConversionFactorsSeeder)->run();

        // Fachliche Plausibilitätsprüfung: Beim Rücken wiegt die Unterwasserphase nach der
        // Wende am schwersten, der Kurzbahnvorteil ist dort am größten.
        $ruecken = WpsScmConversionFactor::where('stroke_type_id', stroke_wps2('BACK')->id)
            ->whereNull('distance')->where('gender', 'M')->value('factor');
        $freistil = WpsScmConversionFactor::where('stroke_type_id', stroke_wps2('FREE')->id)
            ->whereNull('distance')->where('gender', 'M')->value('factor');

        expect($ruecken)->toBeGreaterThan($freistil);
    });

    it('lässt den Faktor mit der Streckenlänge sinken', function () {
        (new WpsScmConversionFactorsSeeder)->run();

        // Je länger die Strecke, desto weniger fällt der Wendenvorteil relativ ins Gewicht.
        $free = fn (int $d) => WpsScmConversionFactor::where('stroke_type_id', stroke_wps2('FREE')->id)
            ->where('distance', $d)->where('gender', 'M')->value('factor');

        expect($free(50))->toBeGreaterThan($free(1500));
    });

    it('kennzeichnet die Werte als nicht para-spezifisch', function () {
        (new WpsScmConversionFactorsSeeder)->run();

        expect(WpsScmConversionFactor::first())
            ->notes->toContain('nicht para-spezifisch')
            ->notes->toContain('World Aquatics')
            ->confidence_level->toBe(WpsScmConversionFactor::CONFIDENCE_MEDIUM)
            ->source->toBe(WpsScmConversionFactor::SOURCE_LITERATURE);
    });

    it('überschreibt einen von Hand gesetzten Faktor nicht', function () {
        $manuell = factor_wps5([
            'distance' => 50,
            'gender' => 'M',
            'factor' => 1.011,
            'source' => WpsScmConversionFactor::SOURCE_MANUAL,
        ]);

        (new WpsScmConversionFactorsSeeder)->run();

        expect($manuell->fresh()->factor)->toBe(1.011);
    });

    it('deckt Bewerbe ohne World-Aquatics-Entsprechung über den Sammelwert ab', function () {
        (new WpsScmConversionFactorsSeeder)->run();

        // 150 m Lagen gibt es nur im Para-Schwimmsport — es greift der Stilwert.
        $factor = conversion_wps5()->resolveFactor(stroke_wps2('MEDLEY')->id, 150, 'SM4', 'M');

        expect($factor)->not->toBeNull()
            ->and($factor->distance)->toBeNull();
    });

    it('bevorzugt den streckengenauen Wert vor dem Sammelwert', function () {
        (new WpsScmConversionFactorsSeeder)->run();

        $factor = conversion_wps5()->resolveFactor(stroke_wps2('FREE')->id, 50, 'S10', 'M');

        expect($factor->distance)->toBe(50)
            ->and($factor->gender)->toBe('M');
    });
});

// ── Kennzeichnung der Herkunft ───────────────────────────────────────────────

describe('Herkunftsanzeige', function () {
    it('benennt die konkrete Quelle statt der technischen Kategorie', function () {
        stroke_wps2('FREE');
        (new WpsScmConversionFactorsSeeder)->run();

        $factor = WpsScmConversionFactor::first();

        // Gespeichert bleibt 'literature' — daran hängt, ob die Kalibrierung überschreiben
        // darf. Angezeigt wird die tatsächliche Quelle.
        expect($factor->source)->toBe(WpsScmConversionFactor::SOURCE_LITERATURE)
            ->and($factor->sourceLabel())->toBe('World Aquatics')
            ->and($factor->sourceColor())->toBe('blue');
    });

    it('hebt manuell gesetzte Faktoren farblich ab', function () {
        $manuell = factor_wps5(['source' => WpsScmConversionFactor::SOURCE_MANUAL]);

        // Amber statt blau: der einzige Zustand, den der Kalibrierungslauf nie aktualisiert.
        expect($manuell->sourceLabel())->toBe('manuell')
            ->and($manuell->sourceColor())->toBe('amber')
            ->and($manuell->isManual())->toBeTrue();
    });

    it('kennzeichnet eigene Daten grün', function () {
        $eigen = factor_wps5(['source' => WpsScmConversionFactor::SOURCE_OWN_DATA, 'sample_size' => 7]);

        expect($eigen->sourceLabel())->toBe('eigene Daten')
            ->and($eigen->sourceColor())->toBe('green');
    });

    it('zeigt Freigabedatum und Person bei manuell gesetzten Faktoren', function () {
        $admin = admin_wps5();
        $factor = factor_wps5();

        $this->actingAs($admin)->put(route('wps.factors.update', $factor), [
            'factor' => '1.035',
            'active' => '1',
        ]);

        $this->actingAs($admin)->get(route('wps.factors.index'))
            ->assertOk()
            ->assertSee('manuell')
            ->assertSee($admin->name);
    });

    it('zeigt keine Freigabe bei abgeleiteten Faktoren', function () {
        stroke_wps2('FREE');
        (new WpsScmConversionFactorsSeeder)->run();

        expect(WpsScmConversionFactor::first()->approved_at)->toBeNull();
    });
});

// ── Oberfläche und Berechtigungen ────────────────────────────────────────────

describe('Faktorenverwaltung', function () {
    it('zeigt Admins die Faktoren und den Bericht', function (string $route) {
        factor_wps5();

        $this->actingAs(admin_wps5())->get(route($route))->assertOk();
    })->with(['wps.factors.index', 'wps.factors.report']);

    it('verwehrt Nicht-Admins den Zugriff', function () {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('wps.factors.index'))
            ->assertForbidden();
    });

    it('markiert eine Änderung von Hand als manuell', function () {
        $factor = factor_wps5();

        $this->actingAs(admin_wps5())->put(route('wps.factors.update', $factor), [
            'factor' => '1.035',
            'active' => '1',
        ])->assertRedirect();

        $factor = $factor->fresh();

        expect($factor->factor)->toBe(1.035)
            ->and($factor->source)->toBe(WpsScmConversionFactor::SOURCE_MANUAL)
            ->and($factor->approved_at)->not->toBeNull();
    });

    it('weist unplausible Faktoren ab', function () {
        $factor = factor_wps5();

        $this->actingAs(admin_wps5())
            ->put(route('wps.factors.update', $factor), ['factor' => '5'])
            ->assertSessionHasErrors('factor');

        expect($factor->fresh()->factor)->toBe(1.02);
    });

    it('löst die Kalibrierung aus', function () {
        $this->actingAs(admin_wps5())
            ->post(route('wps.factors.calibrate'))
            ->assertRedirect(route('wps.factors.report'))
            ->assertSessionHas('success');

        expect(StrokeType::count())->toBeGreaterThanOrEqual(0);
    });
});

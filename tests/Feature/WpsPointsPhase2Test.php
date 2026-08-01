<?php

use App\Models\Meet;
use App\Models\PointSystem;
use App\Models\Result;
use App\Models\WpsPointVersion;
use App\Services\WpsPointCalculationService;
use App\Services\WpsPointVersionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('wps-points-p2');

// Die Helper (parameter_wps2, result_wps2, version_wps2, …) stammen aus
// WpsPointCalculatorTest.php und stehen als globale Funktionen zur Verfügung.

function service_wps2(): WpsPointCalculationService
{
    return app(WpsPointCalculationService::class);
}

function resolver_wps2(): WpsPointVersionResolver
{
    return app(WpsPointVersionResolver::class);
}

/** Ordnet einem Meet das Punktesystem WPS zu, optional mit fester Version. */
function enableWps_wps2(Meet $meet, ?WpsPointVersion $version = null): void
{
    $system = PointSystem::firstOrCreate(
        ['code' => PointSystem::CODE_WPS],
        ['name' => 'World Para Swimming Points']
    );

    $meet->pointSystems()->attach($system, ['wps_point_version_id' => $version?->id]);
}

// ── Versionsauflösung ────────────────────────────────────────────────────────

describe('WpsPointVersionResolver', function () {
    it('bevorzugt die ausdrücklich übergebene Version', function () {
        $meet = result_wps2()->meet;
        $automatisch = version_wps2();
        $explizit = WpsPointVersion::create([
            'label' => 'Sonderversion', 'year' => 2020, 'version' => '9',
            'valid_from' => '2020-01-01',
        ]);
        enableWps_wps2($meet, $automatisch);

        expect(resolver_wps2()->resolveForMeet($meet, $explizit)->id)->toBe($explizit->id);
    });

    it('nimmt danach die am Meet hinterlegte Version', function () {
        $meet = result_wps2()->meet;
        version_wps2();
        $hinterlegt = WpsPointVersion::create([
            'label' => 'WPS 2025', 'year' => 2025, 'version' => '1',
            'valid_from' => '2025-01-01', 'valid_until' => '2025-12-31',
        ]);
        enableWps_wps2($meet, $hinterlegt);

        expect(resolver_wps2()->resolveForMeet($meet)->id)->toBe($hinterlegt->id);
    });

    it('fällt auf die zum Wettkampfdatum gültige Version zurück', function () {
        $meet = result_wps2()->meet;
        $aktuell = version_wps2();
        enableWps_wps2($meet);

        expect(resolver_wps2()->resolveForMeet($meet)->id)->toBe($aktuell->id);
    });

    it('verwendet eine am Meet hinterlegte Version auch nach ihrer Archivierung', function () {
        $meet = result_wps2()->meet;
        $archiviert = WpsPointVersion::create([
            'label' => 'WPS 2025', 'year' => 2025, 'version' => '1',
            'valid_from' => '2025-01-01',
            'status' => WpsPointVersion::STATUS_ARCHIVED,
        ]);
        enableWps_wps2($meet, $archiviert);

        expect(resolver_wps2()->resolveForMeet($meet)->id)->toBe($archiviert->id);
    });

    it('ignoriert archivierte Versionen bei der automatischen Zuordnung', function () {
        $meet = result_wps2()->meet;
        WpsPointVersion::create([
            'label' => 'WPS 2026', 'year' => 2026, 'version' => '1',
            'valid_from' => '2026-01-01',
            'status' => WpsPointVersion::STATUS_ARCHIVED,
        ]);

        expect(resolver_wps2()->resolveForMeet($meet))->toBeNull();
    });

    it('wählt bei überlappenden Zeiträumen die zuletzt begonnene Version', function () {
        $meet = result_wps2()->meet;
        WpsPointVersion::create([
            'label' => 'WPS 2026', 'year' => 2026, 'version' => '1',
            'valid_from' => '2026-01-01',
        ]);
        $korrektur = WpsPointVersion::create([
            'label' => 'WPS 2026 Korrektur', 'year' => 2026, 'version' => '2',
            'valid_from' => '2026-03-01',
        ]);

        expect(resolver_wps2()->resolveForMeet($meet)->id)->toBe($korrektur->id);
    });

    it('greift am ersten und letzten Gültigkeitstag', function (string $meetDate) {
        $result = result_wps2();
        $result->meet->update(['start_date' => $meetDate]);
        $version = WpsPointVersion::create([
            'label' => 'WPS 2026', 'year' => 2026, 'version' => '1',
            'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31',
        ]);

        expect(resolver_wps2()->resolveForMeet($result->meet->fresh())?->id)->toBe($version->id);
    })->with([
        'erster Gültigkeitstag' => ['2026-01-01'],
        'letzter Gültigkeitstag' => ['2026-12-31'],
    ]);

    it('liefert null, wenn keine Version passt', function () {
        expect(resolver_wps2()->resolveForMeet(result_wps2()->meet))->toBeNull();
    });
});

// ── Massenberechnung ─────────────────────────────────────────────────────────

describe('recalculateForMeet', function () {
    it('speichert Punkte, Version, Parametersatz, Typ und Zeitstempel', function () {
        $parameter = parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        $result = result_wps2(['swim_time' => 5700, 'sport_class' => 'S2']);

        $summary = service_wps2()->recalculateForMeet($result->meet);
        $result = $result->fresh();

        expect($summary['updated'])->toBe(1)
            ->and($summary['skipped'])->toBe(0)
            ->and($result->wps_points)->toBe(939)
            ->and($result->wps_point_version_id)->toBe(version_wps2()->id)
            ->and($result->wps_point_parameter_id)->toBe($parameter->id)
            ->and($result->wps_calculation_type)->toBe(Result::WPS_TYPE_OFFICIAL)
            ->and($result->wps_calculated_at)->not->toBeNull();
    });

    it('fasst die Gründe für übersprungene Ergebnisse zusammen', function () {
        parameter_wps2();
        $result = result_wps2(['status' => 'DSQ']);

        $summary = service_wps2()->recalculateForMeet($result->meet);

        expect($summary['updated'])->toBe(0)
            ->and($summary['skipped'])->toBe(1)
            ->and($summary['skipped_reasons'])->toHaveKey('Ergebnisstatus DSQ')
            ->and($summary['skipped_results'][$result->id])->toBe('Ergebnisstatus DSQ');
    });

    it('meldet alle Ergebnisse als übersprungen, wenn keine Version passt', function () {
        $result = result_wps2();

        $summary = service_wps2()->recalculateForMeet($result->meet);

        expect($summary['updated'])->toBe(0)
            ->and($summary['skipped'])->toBe(1)
            ->and($summary['skipped_reasons'])
            ->toHaveKey('keine gültige WPS-Version für das Wettkampfdatum');
    });

    it('überschreibt bestehende Werte standardmäßig', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        $result = result_wps2(['swim_time' => 5700, 'sport_class' => 'S2']);
        $result->update(['wps_points' => 1]);

        service_wps2()->recalculateForMeet($result->meet);

        expect($result->fresh()->wps_points)->toBe(939);
    });

    it('lässt bestehende Werte mit onlyMissing unangetastet', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        $result = result_wps2(['swim_time' => 5700, 'sport_class' => 'S2']);
        $result->update(['wps_points' => 1]);

        $summary = service_wps2()->recalculateForMeet($result->meet, null, true);

        expect($summary['updated'])->toBe(0)
            ->and($result->fresh()->wps_points)->toBe(1);
    });

    it('rechnet mit onlyMissing die noch leeren Ergebnisse', function () {
        parameter_wps2();
        $result = result_wps2();

        $summary = service_wps2()->recalculateForMeet($result->meet, null, true);

        expect($summary['updated'])->toBe(1)
            ->and($result->fresh()->wps_points)->not->toBeNull();
    });

    it('lässt results.points unberührt', function () {
        parameter_wps2();
        $result = result_wps2();
        $result->update(['points' => 700]);

        service_wps2()->recalculateForMeet($result->meet);

        expect($result->fresh()->points)->toBe(700);
    });
});

// ── Einzelergebnis ───────────────────────────────────────────────────────────

describe('recalculateForResult', function () {
    it('berechnet und speichert ein einzelnes Ergebnis', function () {
        parameter_wps2();
        $result = result_wps2();

        $calculated = service_wps2()->recalculateForResult($result);

        expect($calculated->wasCalculated())->toBeTrue()
            ->and($result->fresh()->wps_points)->toBe($calculated->points);
    });

    it('löscht bestehende Werte, wenn das Ergebnis nicht mehr berechenbar ist', function () {
        parameter_wps2();
        $result = result_wps2();
        service_wps2()->recalculateForResult($result);

        // Nachträgliche Disqualifikation — die Punktzahl darf nicht stehen bleiben.
        $result->update(['status' => 'DSQ']);
        service_wps2()->recalculateForResult($result->fresh());

        $result = $result->fresh();

        expect($result->wps_points)->toBeNull()
            ->and($result->wps_point_version_id)->toBeNull()
            ->and($result->wps_point_parameter_id)->toBeNull()
            ->and($result->wps_calculation_type)->toBeNull()
            ->and($result->wps_calculated_at)->toBeNull();
    });
});

// ── Jahresberechnung ─────────────────────────────────────────────────────────

describe('recalculateForYear', function () {
    it('berücksichtigt nur Veranstaltungen des gewählten Jahres', function () {
        parameter_wps2();
        $imJahr = result_wps2();
        $ausserhalb = result_wps2();
        $ausserhalb->meet->update(['start_date' => '2027-05-01']);

        $summary = service_wps2()->recalculateForYear(2026);

        expect($summary['updated'])->toBe(1)
            ->and($imJahr->fresh()->wps_points)->not->toBeNull()
            ->and($ausserhalb->fresh()->wps_points)->toBeNull();
    });

    it('erfasst auch Veranstaltungen an den Jahresgrenzen', function () {
        parameter_wps2();
        $silvester = result_wps2();
        $silvester->meet->update(['start_date' => '2026-12-31']);
        $neujahr = result_wps2();
        $neujahr->meet->update(['start_date' => '2026-01-01']);

        $summary = service_wps2()->recalculateForYear(2026);

        expect($summary['updated'])->toBe(2);
    });

    it('summiert die Gründe über alle Veranstaltungen', function () {
        parameter_wps2();
        result_wps2(['status' => 'DSQ']);
        result_wps2(['status' => 'DSQ']);

        $summary = service_wps2()->recalculateForYear(2026);

        expect($summary['skipped'])->toBe(2)
            ->and($summary['skipped_reasons']['Ergebnisstatus DSQ'])->toBe(2);
    });
});

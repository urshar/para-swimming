<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\PointSystem;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Models\WpsPointParameter;
use App\Models\WpsPointVersion;
use App\Models\WpsScmDerivation;
use Database\Seeders\PointSystemsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('wps-points-p1');

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeNation_wps1(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], [
        'name_de' => 'Österreich',
        'name_en' => 'Austria',
    ]);
}

function makeStrokeType_wps1(string $code = 'FREE'): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $code], [
        'code' => $code,
        'name_de' => $code,
        'name_en' => $code,
    ]);
}

function makeVersion_wps1(int $year = 2026, ?string $version = '1'): WpsPointVersion
{
    return WpsPointVersion::create([
        'label' => "WPS $year",
        'year' => $year,
        'version' => $version,
        'source' => 'World Para Swimming Point Scores',
        'official' => true,
        'valid_from' => "$year-01-01",
    ]);
}

/** @param array<string, mixed> $overrides */
function makeParameter_wps1(WpsPointVersion $version, array $overrides = []): WpsPointParameter
{
    return WpsPointParameter::create(array_merge([
        'wps_point_version_id' => $version->id,
        'course' => WpsPointParameter::COURSE_LCM,
        'gender' => WpsPointParameter::GENDER_MALE,
        'stroke_type_id' => makeStrokeType_wps1()->id,
        'distance' => 50,
        'relay_count' => 1,
        'sport_class' => 'S10',
        'parameter_a' => 1200,
        'parameter_b' => 6.190278,
        'parameter_c' => 188.441,
        'official' => true,
    ], $overrides));
}

function makeMeet_wps1(string $course = 'LCM'): Meet
{
    return Meet::create([
        'name' => 'Testmeeting',
        'nation_id' => makeNation_wps1()->id,
        'course' => $course,
        'start_date' => '2026-05-01',
    ]);
}

function makeResult_wps1(Meet $meet): Result
{
    $club = Club::create([
        'name' => 'Testverein',
        'short_name' => 'TV',
        'nation_id' => makeNation_wps1()->id,
    ]);

    $athlete = Athlete::create([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'gender' => 'M',
        'birth_date' => '2005-06-01',
        'club_id' => $club->id,
        'nation_id' => makeNation_wps1()->id,
    ]);

    $event = SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => makeStrokeType_wps1()->id,
        'distance' => 50,
        'relay_count' => 1,
        'gender' => 'M',
    ]);

    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => 2637,
        'sport_class' => 'S10',
    ]);
}

// ── point_systems ────────────────────────────────────────────────────────────

describe('PointSystem', function () {
    it('legt ein Punktesystem an und castet active als boolean', function () {
        $system = PointSystem::create([
            'code' => 'TEST',
            'name' => 'Testsystem',
            'active' => 1,
        ]);

        expect($system->active)->toBeTrue()
            ->and($system->code)->toBe('TEST')
            ->and($system->isWps())->toBeFalse();
    });

    it('erzwingt eindeutige Codes', function () {
        PointSystem::create(['code' => 'WPS', 'name' => 'Erstes']);

        expect(fn () => PointSystem::create(['code' => 'WPS', 'name' => 'Zweites']))
            ->toThrow(QueryException::class);
    });

    it('der Seeder legt WA, WPS und OBSV1000 an und ist wiederholbar', function () {
        (new PointSystemsSeeder)->run();
        (new PointSystemsSeeder)->run();

        expect(PointSystem::count())->toBe(3)
            ->and(PointSystem::where('code', PointSystem::CODE_WPS)->first()->isWps())->toBeTrue()
            ->and(PointSystem::active()->pluck('code')->all())
            ->toBe([PointSystem::CODE_WORLD_AQUATICS, PointSystem::CODE_WPS]);
    });
});

// ── wps_point_versions ───────────────────────────────────────────────────────

describe('WpsPointVersion', function () {
    it('ist per Default aktiv und castet die Datumsfelder', function () {
        $version = makeVersion_wps1();

        expect($version->status)->toBe(WpsPointVersion::STATUS_ACTIVE)
            ->and($version->isArchived())->toBeFalse()
            ->and($version->official)->toBeTrue()
            ->and($version->valid_from->format('Y-m-d'))->toBe('2026-01-01')
            ->and($version->valid_until)->toBeNull();
    });

    it('verhindert dieselbe Jahr/Versions-Kombination zweimal', function () {
        makeVersion_wps1();

        expect(fn () => makeVersion_wps1())->toThrow(QueryException::class);
    });

    it('scopeValidOn findet die zum Wettkampfdatum gültige Version', function () {
        $alt = WpsPointVersion::create([
            'label' => 'WPS 2024', 'year' => 2024, 'version' => '1',
            'valid_from' => '2024-01-01', 'valid_until' => '2025-12-31',
        ]);
        $neu = makeVersion_wps1();

        expect(WpsPointVersion::validOn('2025-06-15')->pluck('id')->all())->toBe([$alt->id])
            ->and(WpsPointVersion::validOn('2026-06-15')->pluck('id')->all())->toBe([$neu->id])
            ->and(WpsPointVersion::validOn('2023-06-15')->get())->toBeEmpty();
    });

    it('scopeValidOn ignoriert Versionen ohne valid_from', function () {
        WpsPointVersion::create(['label' => 'Ohne Zeitraum', 'year' => 2026, 'version' => '2']);

        expect(WpsPointVersion::validOn('2026-06-15')->get())->toBeEmpty();
    });

    it('scopeActive blendet archivierte Versionen aus', function () {
        makeVersion_wps1();
        WpsPointVersion::create([
            'label' => 'Alt', 'year' => 2020, 'version' => '1',
            'status' => WpsPointVersion::STATUS_ARCHIVED,
        ]);

        expect(WpsPointVersion::active()->count())->toBe(1);
    });

    it('ist nur löschbar, solange kein Ergebnis darauf verweist', function () {
        $version = makeVersion_wps1();
        $result = makeResult_wps1(makeMeet_wps1());

        expect($version->isDeletable())->toBeTrue();

        $result->update(['wps_point_version_id' => $version->id]);

        expect($version->fresh()->isDeletable())->toBeFalse();
    });
});

// ── wps_point_parameters ─────────────────────────────────────────────────────

describe('WpsPointParameter', function () {
    it('speichert die Gompertz-Parameter verlustfrei', function () {
        $parameter = makeParameter_wps1(makeVersion_wps1())->fresh();

        expect($parameter->parameter_a)->toBe(1200.0)
            ->and($parameter->parameter_b)->toBe(6.190278)
            ->and($parameter->parameter_c)->toBe(188.441);
    });

    it('erzwingt die Eindeutigkeit der Merkmalskombination', function () {
        $version = makeVersion_wps1();
        makeParameter_wps1($version);

        expect(fn () => makeParameter_wps1($version))->toThrow(QueryException::class);
    });

    it('erlaubt dieselbe Kombination in einer anderen Version', function () {
        makeParameter_wps1(makeVersion_wps1());
        makeParameter_wps1(makeVersion_wps1(2027));

        expect(WpsPointParameter::count())->toBe(2);
    });

    it('unterscheidet Sportklassen nach Kategorie', function () {
        $version = makeVersion_wps1();
        makeParameter_wps1($version, ['sport_class' => 'S10']);
        makeParameter_wps1($version, ['sport_class' => 'SB10', 'stroke_type_id' => makeStrokeType_wps1('BREAST')->id]);

        expect(WpsPointParameter::count())->toBe(2)
            ->and(WpsPointParameter::where('sport_class', 'SB10')->first()->sport_class_category)->toBe('SB')
            ->and(WpsPointParameter::where('sport_class', 'S10')->first()->sport_class_category)->toBe('S');
    });

    it('leitet den Berechnungstyp aus dem Parametersatz ab, nicht aus dem Kurs', function () {
        $version = makeVersion_wps1();

        $offiziell = makeParameter_wps1($version, ['official' => true]);
        $abgeleitet = makeParameter_wps1($version, [
            'course' => WpsPointParameter::COURSE_SCM,
            'official' => false,
        ]);

        expect($offiziell->calculationType())->toBe(Result::WPS_TYPE_OFFICIAL)
            ->and($abgeleitet->calculationType())->toBe(Result::WPS_TYPE_ESTIMATED);
    });

    it('wird beim Löschen der Version mitgelöscht', function () {
        $version = makeVersion_wps1();
        makeParameter_wps1($version);

        $version->delete();

        expect(WpsPointParameter::count())->toBe(0);
    });
});

// ── wps_scm_derivations ──────────────────────────────────────────────────────

describe('WpsScmDerivation', function () {
    it('dokumentiert eine Ableitung inklusive Freigabe', function () {
        $version = makeVersion_wps1();
        $user = User::factory()->create(['is_admin' => true]);

        $derivation = WpsScmDerivation::create([
            'wps_point_version_id' => $version->id,
            'conversion_method' => WpsScmDerivation::METHOD_PERFORMANCE_RATIO,
            'confidence_level' => WpsScmDerivation::CONFIDENCE_MEDIUM,
            'sample_size' => 42,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        expect($derivation->isApproved())->toBeTrue()
            ->and($derivation->sample_size)->toBe(42)
            ->and($derivation->approvedBy->is($user))->toBeTrue()
            ->and($derivation->version->is($version))->toBeTrue();
    });

    it('gilt ohne approved_at als nicht freigegeben', function () {
        $derivation = WpsScmDerivation::create([
            'wps_point_version_id' => makeVersion_wps1()->id,
            'conversion_method' => WpsScmDerivation::METHOD_DISTANCE_ADJUSTMENT,
        ]);

        expect($derivation->isApproved())->toBeFalse()
            ->and($derivation->approved_by)->toBeNull();
    });
});

// ── meet_point_system ────────────────────────────────────────────────────────

describe('Meet ↔ PointSystem', function () {
    it('ordnet einer Veranstaltung Punktesysteme zu', function () {
        $meet = makeMeet_wps1();
        (new PointSystemsSeeder)->run();

        $wps = PointSystem::where('code', PointSystem::CODE_WPS)->first();
        $meet->pointSystems()->attach($wps);

        expect($meet->fresh()->pointSystems)->toHaveCount(1)
            ->and($meet->hasWpsPointsEnabled())->toBeTrue();
    });

    it('meldet WPS als nicht aktiviert, wenn nur World Aquatics zugeordnet ist', function () {
        $meet = makeMeet_wps1();
        (new PointSystemsSeeder)->run();

        $meet->pointSystems()->attach(
            PointSystem::where('code', PointSystem::CODE_WORLD_AQUATICS)->first()
        );

        expect($meet->hasWpsPointsEnabled())->toBeFalse();
    });

    it('speichert eine abweichende WPS-Version am Pivot', function () {
        $meet = makeMeet_wps1();
        $version = makeVersion_wps1();
        $system = PointSystem::create(['code' => PointSystem::CODE_WPS, 'name' => 'WPS']);

        $meet->pointSystems()->attach($system, ['wps_point_version_id' => $version->id]);

        $pivot = $meet->fresh()->pointSystems->first()->getRelation('pivot');

        expect($pivot->getAttribute('wps_point_version_id'))->toBe($version->id);
    });

    it('lässt dasselbe Punktesystem nicht zweimal je Veranstaltung zu', function () {
        $meet = makeMeet_wps1();
        $system = PointSystem::create(['code' => PointSystem::CODE_WPS, 'name' => 'WPS']);
        $meet->pointSystems()->attach($system);

        expect(fn () => $meet->pointSystems()->attach($system))->toThrow(QueryException::class);
    });
});

// ── results-Erweiterung ──────────────────────────────────────────────────────

describe('Result — WPS-Felder', function () {
    it('legt ein Ergebnis ohne WPS-Werte an', function () {
        $result = makeResult_wps1(makeMeet_wps1());

        expect($result->wps_points)->toBeNull()
            ->and($result->hasWpsPoints())->toBeFalse()
            ->and($result->hasEstimatedWpsPoints())->toBeFalse();
    });

    it('speichert Punkte, Version, Parametersatz und Berechnungstyp', function () {
        $version = makeVersion_wps1();
        $parameter = makeParameter_wps1($version);
        $result = makeResult_wps1(makeMeet_wps1());

        $result->update([
            'wps_points' => 1093,
            'wps_point_version_id' => $version->id,
            'wps_point_parameter_id' => $parameter->id,
            'wps_calculation_type' => Result::WPS_TYPE_OFFICIAL,
            'wps_calculated_at' => now(),
        ]);

        $result = $result->fresh();

        expect($result->wps_points)->toBe(1093)
            ->and($result->hasWpsPoints())->toBeTrue()
            ->and($result->wpsPointVersion->is($version))->toBeTrue()
            ->and($result->wpsPointParameter->is($parameter))->toBeTrue()
            ->and($result->wps_calculated_at)->not->toBeNull();
    });

    it('erlaubt Punkte über 1000 — die Asymptote a beträgt 1200', function () {
        $result = makeResult_wps1(makeMeet_wps1());
        $result->update(['wps_points' => 1197]);

        expect($result->fresh()->wps_points)->toBe(1197);
    });

    it('kennzeichnet SCM-Punkte als geschätzt', function () {
        $result = makeResult_wps1(makeMeet_wps1('SCM'));
        $result->update(['wps_points' => 842, 'wps_calculation_type' => Result::WPS_TYPE_ESTIMATED]);

        expect($result->fresh()->hasEstimatedWpsPoints())->toBeTrue();
    });

    it('lässt die Punkte bestehen, wenn die Version gelöscht wird', function () {
        $version = makeVersion_wps1();
        $result = makeResult_wps1(makeMeet_wps1());
        $result->update(['wps_points' => 856, 'wps_point_version_id' => $version->id]);

        $version->delete();
        $result = $result->fresh();

        expect($result->wps_points)->toBe(856)
            ->and($result->wps_point_version_id)->toBeNull();
    });

    it('lässt results.points unberührt — es gehört World Aquatics', function () {
        $result = makeResult_wps1(makeMeet_wps1());
        $result->update(['points' => 700]);

        $result->update(['wps_points' => 1093, 'wps_calculation_type' => Result::WPS_TYPE_OFFICIAL]);

        expect($result->fresh()->points)->toBe(700);
    });
});

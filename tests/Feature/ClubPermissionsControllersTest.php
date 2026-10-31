<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Livewire\Admin\BaseTimeTable;
use App\Livewire\Admin\ChampionshipStandardTable;
use App\Models\Athlete;
use App\Models\AthletePerformanceNote;
use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\StrokeType;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('club-permissions-p3');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/**
 * Nicht-Admin mit Verein und einzeln gesetzter Matrix (ohne Vorlage-Rechte).
 *
 * @param  array<string, array{read: AccessScope, write: AccessScope}>  $matrix
 *
 * @throws Throwable
 */
function userWithMatrix_cp3c(array $matrix, ?int $clubId = null): User
{
    $user = User::factory()->create([
        'club_id' => $clubId ?? makeClub_p5()->id,
        'role_template' => RoleTemplate::Empty,
    ]);
    app(PermissionTemplateService::class)->setPermissions($user, $matrix);

    return $user->fresh();
}

/** @return array{read: AccessScope, write: AccessScope} */
function scopes_cp3c(AccessScope $read, AccessScope $write = AccessScope::None): array
{
    return ['read' => $read, 'write' => $write];
}

/** Basiswert-Version mit einer Kategorie und einer manuellen Zelle. */
function baseTimeSetup_cp3c(): array
{
    $stroke = StrokeType::firstOrCreate(['code' => 'FREE'], ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']);
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => null]);
    $category = BaseTimeCategory::create(['code' => 'LC_MEN', 'course' => 'LCM', 'gender' => 'M', 'label' => 'LC Men']);
    $discipline = BaseTimeDiscipline::create(['code' => '100FR', 'distance' => 100, 'relay_count' => 1, 'stroke_type_id' => $stroke->id]);
    $sportClass = BaseTimeSportClass::create(['code' => 'S1', 'sort_order' => 1]);
    $row = BaseTime::create([
        'base_time_version_id' => $version->id,
        'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id,
        'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => 6000,
        'value_type' => BaseTime::TYPE_MANUAL,
    ]);

    return [$version, $category, $row];
}

// ── Athleten-Sichtbarkeit ─────────────────────────────────────────────────────

it('zeigt bei Athleten lesen "eigener Verein" nur die eigenen Athleten', /** @throws Throwable */ function () {
    $own = makeAthlete_p5(makeClub_p5());
    $foreign = makeAthlete_p5(makeClub_p5());
    $foreign->update(['last_name' => 'Fremdling']);
    $user = userWithMatrix_cp3c([Module::Athletes->value => scopes_cp3c(AccessScope::Own)], $own->club_id);

    test()->actingAs($user)->get(route('athletes.index'))
        ->assertOk()
        ->assertSee($own->last_name)
        ->assertDontSee('Fremdling');

    test()->actingAs($user)->get(route('athletes.show', $own))->assertOk();
    test()->actingAs($user)->get(route('athletes.show', $foreign))->assertForbidden();

    expect(Athlete::visibleTo($user)->pluck('id')->all())->toBe([$own->id]);
});

it('zeigt bei Athleten lesen "alle" jeden Athleten', /** @throws Throwable */ function () {
    $foreign = makeAthlete_p5(makeClub_p5());
    $user = userWithMatrix_cp3c([Module::Athletes->value => scopes_cp3c(AccessScope::All)]);

    test()->actingAs($user)->get(route('athletes.show', $foreign))->assertOk();

    expect(Athlete::visibleTo($user)->count())->toBe(1)
        ->and(Athlete::visibleTo(userWithMatrix_cp3c([]))->count())->toBe(0);
});

it('verwehrt die WPS-Analyse fremder Athleten und filtert die Athletenauswahl', /** @throws Throwable */ function () {
    $own = makeAthlete_p5(makeClub_p5());
    $foreign = makeAthlete_p5(makeClub_p5());
    $foreign->update(['last_name' => 'Fremdling']);
    $user = userWithMatrix_cp3c([
        Module::Athletes->value => scopes_cp3c(AccessScope::Own),
        Module::Wps->value => scopes_cp3c(AccessScope::Own),
        Module::Statistics->value => scopes_cp3c(AccessScope::All),
    ], $own->club_id);

    test()->actingAs($user)->get(route('wps.athletes.show', $own))->assertOk();
    test()->actingAs($user)->get(route('wps.athletes.show', $foreign))->assertForbidden();
    test()->actingAs($user)->get(route('wps.athletes.pdf', $foreign))->assertForbidden();

    test()->actingAs($user)->get(route('wps.athletes.picker'))
        ->assertOk()
        ->assertSee($own->last_name)
        ->assertDontSee('Fremdling');
});

it('zeigt auf der Seite eines fremden Vereins keine Athletenliste', function () {
    $ownClub = makeClub_p5();
    $foreignClub = makeClub_p5();
    makeAthlete_p5($ownClub);
    $foreign = makeAthlete_p5($foreignClub);
    $foreign->update(['last_name' => 'Fremdling']);
    $user = User::factory()->create(['club_id' => $ownClub->id]);

    test()->actingAs($user)->get(route('clubs.show', $foreignClub))
        ->assertOk()
        ->assertSee('Die Athleten dieses Vereins sind für Sie nicht sichtbar.')
        ->assertDontSee('Fremdling');

    test()->actingAs($user)->get(route('clubs.show', $ownClub))
        ->assertOk()
        ->assertDontSee('Die Athleten dieses Vereins sind für Sie nicht sichtbar.');
});

it('verlinkt nur sichtbare Athleten, sonst nur den Namen', function () {
    $own = makeAthlete_p5(makeClub_p5());
    $foreign = makeAthlete_p5(makeClub_p5());
    $user = User::factory()->create(['club_id' => $own->club_id]);
    $template = '<x-athlete-link :athlete="$athlete">Name</x-athlete-link>';

    test()->actingAs($user);

    expect(Blade::render($template, ['athlete' => $own]))->toContain(route('athletes.show', $own))
        ->and(Blade::render($template, ['athlete' => $foreign]))->not->toContain(route('athletes.show', $foreign))
        ->and(Blade::render($template, ['athlete' => $foreign]))->toContain('Name');
});

// ── Meldungen ─────────────────────────────────────────────────────────────────

it('lässt mit Meldungen schreiben "alle" auch nach Meldeschluss melden, ohne Admin zu sein', /** @throws Throwable */ function () {
    $meet = makeMeet_p5();
    $meet->forceFill(['is_open' => true, 'entries_deadline' => now()->subDays(3)->toDateString()])->save();
    $all = AccessScope::All;
    $federation = userWithMatrix_cp3c([Module::Entries->value => scopes_cp3c($all, $all)]);
    $coach = User::factory()->create(['club_id' => makeClub_p5()->id, 'role_template' => RoleTemplate::Coach]);
    $reader = userWithMatrix_cp3c([Module::Entries->value => scopes_cp3c(AccessScope::Own)]);

    expect(Gate::forUser($federation)->allows('manageEntries', $meet))->toBeTrue()
        ->and(Gate::forUser($coach)->allows('manageEntries', $meet))->toBeFalse()
        ->and(Gate::forUser($coach)->allows('manageEntries', $meet->forceFill(['entries_deadline' => null])))->toBeTrue()
        ->and(Gate::forUser($reader)->allows('manageEntries', $meet))->toBeFalse();
});

// ── Controller: Schreibrecht statt Admin ──────────────────────────────────────

it('öffnet die Pflege der Module jedem mit Schreibrecht', /** @throws Throwable */ function () {
    $all = AccessScope::All;
    $user = userWithMatrix_cp3c([
        Module::MasterData->value => scopes_cp3c($all, $all),
        Module::QualifyingTimes->value => scopes_cp3c($all, $all),
        Module::CupSettings->value => scopes_cp3c($all, $all),
        Module::BaseTimes->value => scopes_cp3c($all, $all),
    ]);

    test()->actingAs($user)->get(route('kader-types.create'))->assertOk();
    test()->actingAs($user)->get(route('age-groups.create'))->assertOk();
    test()->actingAs($user)->get(route('sport-class-groups.create'))->assertOk();
    test()->actingAs($user)->get(route('qualifying-time-lists.create'))->assertOk();
    test()->actingAs($user)->get(route('cups.create'))->assertOk();
    test()->actingAs($user)->get(route('base-times.versions.create'))->assertOk();
});

it('öffnet Basiswerte mit Leserecht, die Pflege erst mit Schreibrecht', /** @throws Throwable */ function () {
    [$version, $category] = baseTimeSetup_cp3c();
    $reader = userWithMatrix_cp3c([Module::BaseTimes->value => scopes_cp3c(AccessScope::All)]);

    test()->actingAs($reader)->get(route('base-times.versions.index'))
        ->assertOk()
        ->assertDontSee(route('base-times.versions.create'));
    test()->actingAs($reader)->get(route('base-times.categories.show', [$version, $category]))
        ->assertOk()
        ->assertDontSee('Neu berechnen');
    test()->actingAs($reader)->get(route('base-times.categories.export', [$version, $category]))->assertOk();
    test()->actingAs($reader)->get(route('base-times.versions.create'))->assertForbidden();
});

// ── Livewire-Schreibaktionen ──────────────────────────────────────────────────

it('verwehrt Basiswerte speichern und neu berechnen ohne Schreibrecht', /** @throws Throwable */ function () {
    [$version, $category, $row] = baseTimeSetup_cp3c();
    $reader = userWithMatrix_cp3c([Module::BaseTimes->value => scopes_cp3c(AccessScope::All)]);
    $params = ['version' => $version, 'category' => $category];
    $cell = "cells.$row->base_time_discipline_id.$row->base_time_sport_class_id";

    Livewire::actingAs($reader)->test(BaseTimeTable::class, $params)
        ->call('recalculate')
        ->assertForbidden();
    Livewire::actingAs($reader)->test(BaseTimeTable::class, $params)
        ->set($cell, '00:50.00')
        ->assertForbidden();

    expect($row->fresh()->value_centiseconds)->toBe(6000);

    $all = AccessScope::All;
    Livewire::actingAs(userWithMatrix_cp3c([Module::BaseTimes->value => scopes_cp3c($all, $all)]))
        ->test(BaseTimeTable::class, $params)
        ->set($cell, '00:50.00')
        ->assertOk();

    expect($row->fresh()->value_centiseconds)->toBe(5000);
});

it('lässt Normen mit Meisterschaften schreiben ändern, ohne Admin zu sein', /** @throws Throwable */ function () {
    BaseTimeSportClass::firstOrCreate(['code' => 'S7'], ['sort_order' => 7]);
    $stroke = StrokeType::firstOrCreate(['code' => 'FREE'], ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']);
    $championship = Championship::query()->create([
        'name' => 'EM 2026', 'short_name' => 'EM 2026', 'type' => Championship::TYPE_EC, 'year' => 2026,
        'qualification_start' => '2025-01-01', 'qualification_end' => '2026-07-06',
    ]);
    $standard = ChampionshipStandard::query()->create([
        'championship_id' => $championship->id, 'stroke_type_id' => $stroke->id, 'distance' => 100,
        'gender' => 'M', 'sport_class' => 'S7', 'mqs_centiseconds' => 7319,
    ]);
    $all = AccessScope::All;
    $reader = userWithMatrix_cp3c([Module::Championships->value => scopes_cp3c($all)]);
    $writer = userWithMatrix_cp3c([Module::Championships->value => scopes_cp3c($all, $all)]);

    Livewire::actingAs($reader)->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'mqs', '01:10.00')
        ->assertForbidden();
    Livewire::actingAs($writer)->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'mqs', '01:10.00')
        ->assertOk();

    expect($standard->fresh()->mqs_centiseconds)->toBe(7000);
});

// ── Leistungsnotizen ──────────────────────────────────────────────────────────

it('öffnet Leistungsnotizen nur dem eigenen Verein, nicht mit Athleten lesen "alle"', /** @throws Throwable */ function () {
    $athlete = makeAthlete_p5(makeClub_p5());
    $all = AccessScope::All;
    $federation = userWithMatrix_cp3c([Module::Athletes->value => scopes_cp3c($all, $all)]);
    $ownReader = userWithMatrix_cp3c([Module::Athletes->value => scopes_cp3c(AccessScope::Own)], $athlete->club_id);
    $ownWriter = User::factory()->create(['club_id' => $athlete->club_id]);
    $ownWithoutRights = userWithMatrix_cp3c([], $athlete->club_id);
    $view = fn (User $user) => Gate::forUser($user)->allows('viewForAthlete', [AthletePerformanceNote::class, $athlete]);
    $create = fn (User $user) => Gate::forUser($user)->allows('createForAthlete', [AthletePerformanceNote::class, $athlete]);

    expect($view($federation))->toBeFalse()
        ->and($view($ownReader))->toBeTrue()
        ->and($create($ownReader))->toBeFalse()
        ->and($view($ownWriter))->toBeTrue()
        ->and($create($ownWriter))->toBeTrue()
        ->and($view($ownWithoutRights))->toBeFalse();
});

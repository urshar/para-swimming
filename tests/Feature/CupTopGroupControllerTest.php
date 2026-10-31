<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Enums\AccessScope;
use App\Enums\RoleTemplate;
use App\Enums\TopGroupReason;
use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupTopGroupClassification;
use App\Models\Nation;
use App\Models\User;
use App\Services\PermissionTemplateService;
use App\Services\TopGroupClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('cup-top-group-p2');

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_ctg2(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeCupReader_ctg2(): User
{
    $nation = Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'AUT', 'name_en' => 'AUT', 'is_active' => true]);
    $user = User::factory()->create([
        'club_id' => Club::create(['name' => 'Leseverein', 'nation_id' => $nation->id])->id,
        'role_template' => RoleTemplate::Empty,
    ]);
    app(PermissionTemplateService::class)->setPermissions($user, [
        'cup' => ['read' => AccessScope::All, 'write' => AccessScope::None],
    ]);

    return $user->fresh();
}

function makeCup_ctg2(): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V2026', 'valid_from' => '2012-01-01']);

    return Cup::create([
        'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);
}

function makeAthlete_ctg2(string $lastName): Athlete
{
    $nation = Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'AUT', 'name_en' => 'AUT', 'is_active' => true]);

    return Athlete::create([
        'first_name' => 'Max', 'last_name' => $lastName, 'gender' => 'M', 'nation_id' => $nation->id, 'is_active' => true,
    ]);
}

function makeCalculated_ctg2(Cup $cup, Athlete $athlete, bool $isTopGroup): CupTopGroupClassification
{
    return CupTopGroupClassification::create([
        'cup_id' => $cup->id, 'athlete_id' => $athlete->id, 'is_top_group' => $isTopGroup,
        'calculated_is_top_group' => $isTopGroup, 'reason' => TopGroupReason::CarriedOver,
        'last_start_year' => 2025, 'last_start_points' => 512, 'calculated_at' => now()->subDay(),
    ]);
}

// ── Liste ────────────────────────────────────────────────────────────────────

describe('Einstufungsliste', function () {
    it('zeigt eingestufte Athleten mit Grund und Punkten', function () {
        $cup = makeCup_ctg2();
        makeCalculated_ctg2($cup, makeAthlete_ctg2('Huber'), true);

        $this->actingAs(makeAdmin_ctg2())
            ->get(route('cups.top-group.index', $cup))
            ->assertOk()
            ->assertSee('Huber')
            ->assertSee('Status vom Vorjahr')
            ->assertSee('512')
            ->assertSee('Athlet von Hand einstufen');
    });

    it('ist mit Leserecht sichtbar, aber ohne Bearbeiten', function () {
        $cup = makeCup_ctg2();
        $athlete = makeAthlete_ctg2('Huber');
        makeCalculated_ctg2($cup, $athlete, true);

        $this->actingAs(makeCupReader_ctg2())
            ->get(route('cups.top-group.index', $cup))
            ->assertOk()
            ->assertSee('Huber')
            ->assertDontSee('Athlet von Hand einstufen')
            ->assertDontSee(route('cups.top-group.edit', [$cup, $athlete]));
    });

    it('verweigert die Hand-Einstufung ohne Schreibrecht', function () {
        $cup = makeCup_ctg2();
        $athlete = makeAthlete_ctg2('Huber');
        makeCalculated_ctg2($cup, $athlete, true);

        $this->actingAs(makeCupReader_ctg2())
            ->put(route('cups.top-group.update', [$cup, $athlete]), ['is_top_group' => '0', 'manual_note' => 'x'])
            ->assertForbidden();
    });
});

// ── Hand-Einstufung ──────────────────────────────────────────────────────────

describe('Hand-Einstufung', function () {
    it('setzt eine Hand-Einstufung mit Begründung', function () {
        $cup = makeCup_ctg2();
        $athlete = makeAthlete_ctg2('Huber');
        $classification = makeCalculated_ctg2($cup, $athlete, true);
        $admin = makeAdmin_ctg2();

        $this->actingAs($admin)
            ->put(route('cups.top-group.update', [$cup, $athlete]),
                ['is_top_group' => '0', 'manual_note' => 'Verletzungspause'])
            ->assertRedirect(route('cups.top-group.index', $cup))
            ->assertSessionHas('success');

        $classification->refresh();

        expect($classification->is_top_group)->toBeFalse()
            ->and($classification->manual_is_top_group)->toBeFalse()
            ->and($classification->calculated_is_top_group)->toBeTrue()
            ->and($classification->manual_note)->toBe('Verletzungspause')
            ->and($classification->manual_set_by)->toBe($admin->id)
            ->and($classification->calculated_at->isToday())->toBeTrue();
    });

    it('verlangt eine Begründung', function () {
        $cup = makeCup_ctg2();
        $athlete = makeAthlete_ctg2('Huber');
        makeCalculated_ctg2($cup, $athlete, true);

        $this->actingAs(makeAdmin_ctg2())
            ->from(route('cups.top-group.edit', [$cup, $athlete]))
            ->put(route('cups.top-group.update', [$cup, $athlete]), ['is_top_group' => '0', 'manual_note' => ''])
            ->assertSessionHasErrors('manual_note');
    });

    it('stuft einen noch nicht eingestuften Athleten von Hand ein', function () {
        $cup = makeCup_ctg2();
        $athlete = makeAthlete_ctg2('Neu');

        $this->actingAs(makeAdmin_ctg2())
            ->get(route('cups.top-group.create', $cup))
            ->assertOk()
            ->assertSee('Neu');

        $this->actingAs(makeAdmin_ctg2())
            ->post(route('cups.top-group.store', $cup),
                ['athlete_id' => $athlete->id, 'is_top_group' => '1', 'manual_note' => 'Rückkehr nach Pause'])
            ->assertRedirect(route('cups.top-group.index', $cup));

        $classification = CupTopGroupClassification::where('cup_id', $cup->id)->sole();

        expect($classification->is_top_group)->toBeTrue()
            ->and($classification->calculated_is_top_group)->toBeFalse()
            ->and($classification->reason)->toBeNull();
    });

    it('bleibt bei der Neuberechnung erhalten', function () {
        $cup = makeCup_ctg2();
        $athlete = makeAthlete_ctg2('Neu');

        $this->actingAs(makeAdmin_ctg2())
            ->post(route('cups.top-group.store', $cup),
                ['athlete_id' => $athlete->id, 'is_top_group' => '1', 'manual_note' => 'Rückkehr nach Pause']);

        app(TopGroupClassificationService::class)->calculateForCup($cup);

        $classification = CupTopGroupClassification::where('cup_id', $cup->id)->sole();

        expect($classification->is_top_group)->toBeTrue()
            ->and($classification->reason)->toBe(TopGroupReason::CarriedOver);
    });

    it('nimmt eine Hand-Einstufung zurück, es gilt wieder die berechnete', function () {
        $cup = makeCup_ctg2();
        $athlete = makeAthlete_ctg2('Huber');
        $classification = makeCalculated_ctg2($cup, $athlete, true);
        $classification->update(['is_top_group' => false, 'manual_is_top_group' => false, 'manual_note' => 'x']);

        $this->actingAs(makeAdmin_ctg2())
            ->delete(route('cups.top-group.destroy', [$cup, $athlete]))
            ->assertRedirect(route('cups.top-group.index', $cup));

        $classification->refresh();

        expect($classification->is_top_group)->toBeTrue()
            ->and($classification->isManual())->toBeFalse()
            ->and($classification->manual_note)->toBeNull();
    });

    it('löscht beim Zurücknehmen eine nur von Hand angelegte Zeile', function () {
        $cup = makeCup_ctg2();
        $athlete = makeAthlete_ctg2('Neu');

        $this->actingAs(makeAdmin_ctg2())
            ->post(route('cups.top-group.store', $cup),
                ['athlete_id' => $athlete->id, 'is_top_group' => '1', 'manual_note' => 'Test']);
        $this->actingAs(makeAdmin_ctg2())
            ->delete(route('cups.top-group.destroy', [$cup, $athlete]));

        expect(CupTopGroupClassification::where('cup_id', $cup->id)->exists())->toBeFalse();
    });
});

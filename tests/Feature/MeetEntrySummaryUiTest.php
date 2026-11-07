<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meet-entry-summary-p2');

// Melde-Kennzahlen und Medaillenplanung (docs/specs/meet-entry-summary.md), Phase 2: Oberfläche.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php). makeMeet_p5(): LCM, 15.06.2025.

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_mesu(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/**
 * Nicht-Admin, der alle Meldungen lesen, aber Veranstaltungen nicht bearbeiten darf.
 *
 * @throws Throwable
 */
function entriesReader_mesu(): User
{
    $user = User::factory()->create(['club_id' => makeClub_p5()->id, 'role_template' => RoleTemplate::Empty]);
    app(PermissionTemplateService::class)->setPermissions($user, [
        Module::Entries->value => ['read' => AccessScope::All, 'write' => AccessScope::None],
    ]);

    return $user->fresh();
}

function group_mesu(SwimEvent $event, string $name, array $attrs = []): ScoringGroup
{
    return ScoringGroup::create([
        'swim_event_id' => $event->id, 'name' => $name, 'gender' => 'A',
        'title' => ScoringGroup::titleFromName($name),
        'sort_order' => $event->scoringGroups()->count() + 1,
        ...$attrs,
    ]);
}

function entries_mesu(SwimEvent $event, Club $club, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $athlete = makeAthlete_p5($club);
        $athlete->update(['birth_date' => '2010-01-01']);
        Entry::create([
            'meet_id' => $event->meet_id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
            'club_id' => $club->id,
        ]);
    }
}

/** Veranstaltung mit einem ÖJM-Bewerb (3 Meldungen) und einer ÖBSV-Cup-Gruppe ohne Medaillenwertung. */
function meetWithEntries_mesu(): Meet
{
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    group_mesu($event, 'ÖJM PI');
    group_mesu($event, 'ÖBSV Cup', ['awards_medals' => false]);
    entries_mesu($event, makeClub_p5(), 3);

    return $meet;
}

// ── Kennzahlen unter "Alle Meldungen" ─────────────────────────────────────────

it('zeigt die Kennzahlen mit Medaillen, Details und Hinweisen', function () {
    $meet = meetWithEntries_mesu();

    $this->actingAs(admin_mesu())
        ->get(route('meets.entries-overview', $meet))
        ->assertOk()
        ->assertSee('Kennzahlen')
        ->assertSeeInOrder(['Athleten', '3', 'Jugendliche (0–18)', '3', 'Medaillen gesamt', '3'])
        ->assertSeeInOrder(['ÖJM', '1', '1', '0', '1', '3'])
        ->assertSee('Details nach Gruppe und Bewerb (1)')
        ->assertSee('1 G, 1 S, 1 A')
        ->assertSee('Ohne Medaillenwertung: ÖBSV Cup.')
        ->assertSee('Medaillenregel ausschalten')
        ->assertDontSee('In der ÖBM-Wertung');
});

it('zeigt die Kennzahlen ohne Meldungen nicht', function () {
    $meet = makeMeet_p5();
    group_mesu(makeEvent_p5($meet), 'ÖJM PI');

    $this->actingAs(admin_mesu())
        ->get(route('meets.entries-overview', $meet))
        ->assertOk()
        ->assertDontSee('Kennzahlen');
});

it('zeigt die Zahl der ÖBM-Sportler nur mit ÖBM-Gruppe', function () {
    $meet = meetWithEntries_mesu();
    group_mesu($meet->swimEvents()->first(), 'ÖBM S01 - S10');

    $this->actingAs(admin_mesu())
        ->get(route('meets.entries-overview', $meet))
        ->assertSeeInOrder(['In der ÖBM-Wertung', '3']);
});

it('zeigt den Regel-Schalter nur mit Schreibrecht für Veranstaltungen', /** @throws Throwable */ function () {
    $meet = meetWithEntries_mesu();

    $this->actingAs(entriesReader_mesu())
        ->get(route('meets.entries-overview', $meet))
        ->assertOk()
        ->assertSee('Kennzahlen')
        ->assertDontSee('Medaillenregel ausschalten')
        ->assertDontSee('gesammelt ändern');
});

it('schaltet die Medaillenregel aus und wieder ein', function () {
    $meet = meetWithEntries_mesu();
    $admin = admin_mesu();

    $this->actingAs($admin)
        ->put(route('meets.medal-rule.update', $meet), ['medal_reduction_rule' => '0'])
        ->assertRedirect(route('meets.entries-overview', $meet))
        ->assertSessionHas('success', 'Medaillenregel ausgeschaltet.');
    $off = $meet->fresh()->medal_reduction_rule;

    $this->get(route('meets.entries-overview', $meet))
        ->assertSee('Medaillenregel einschalten')
        ->assertSee('1 G, 1 S, 1 B');

    $this->put(route('meets.medal-rule.update', $meet), ['medal_reduction_rule' => '1']);

    expect($off)->toBeFalse()
        ->and($meet->fresh()->medal_reduction_rule)->toBeTrue();
});

it('verweigert den Regel-Schalter ohne Schreibrecht für Veranstaltungen', /** @throws Throwable */ function () {
    $meet = meetWithEntries_mesu();

    $this->actingAs(entriesReader_mesu())
        ->put(route('meets.medal-rule.update', $meet), ['medal_reduction_rule' => '0'])
        ->assertForbidden();

    expect($meet->fresh()->medal_reduction_rule)->toBeTrue();
});

// ── Sammelaktion ──────────────────────────────────────────────────────────────

it('setzt die Medaillenwertung nach Namensanfang, ohne Einschränkungen zu ändern', function () {
    $meet = makeMeet_p5();
    $first = makeEvent_p5($meet, ['event_number' => 1]);
    $second = makeEvent_p5($meet, ['event_number' => 2]);
    $cup = group_mesu($first, 'ÖBSV Cup Herren', ['regional_associations' => 'WBSV', 'regional_mode' => 'only']);
    $cup2 = group_mesu($second, 'öbsv cup Damen');
    $junior = group_mesu($first, 'ÖJM PI');

    $this->actingAs(admin_mesu())
        ->put(route('meets.scoring-group-medals.update', $meet), ['medal_prefix' => 'ÖBSV Cup', 'awards_medals' => '0'])
        ->assertRedirect(route('meets.scoring-group-restrictions', $meet))
        ->assertSessionHas('success', 'Medaillenwertung an 2 Wertungsgruppen ausgeschaltet.');

    expect($cup->fresh()->awards_medals)->toBeFalse()
        ->and($cup->fresh()->regional_associations)->toBe('WBSV')
        ->and($cup2->fresh()->awards_medals)->toBeFalse()
        ->and($junior->fresh()->awards_medals)->toBeTrue();
});

it('meldet, wenn kein Gruppenname mit dem Text beginnt', function () {
    $meet = makeMeet_p5();
    group_mesu(makeEvent_p5($meet), 'ÖJM PI');

    $this->actingAs(admin_mesu())
        ->from(route('meets.scoring-group-restrictions', $meet))
        ->put(route('meets.scoring-group-medals.update', $meet), ['medal_prefix' => 'Cup', 'awards_medals' => '0'])
        ->assertRedirect(route('meets.scoring-group-restrictions', $meet))
        ->assertSessionHas('error', 'Keine Wertungsgruppe beginnt mit "Cup".');
});

it('verweigert die Sammelaktion ohne Schreibrecht für Veranstaltungen', /** @throws Throwable */ function () {
    $meet = makeMeet_p5();
    group_mesu(makeEvent_p5($meet), 'ÖBSV Cup');

    $this->actingAs(entriesReader_mesu())
        ->put(route('meets.scoring-group-medals.update', $meet), ['medal_prefix' => 'ÖBSV', 'awards_medals' => '0'])
        ->assertForbidden();
});

it('zeigt die Medaillenwertung je Gruppenname auf der Einschränkungsseite', function () {
    $meet = makeMeet_p5();
    group_mesu(makeEvent_p5($meet, ['event_number' => 1]), 'ÖBSV Cup');
    group_mesu(makeEvent_p5($meet, ['event_number' => 2]), 'ÖBSV Cup', ['awards_medals' => false]);
    group_mesu(makeEvent_p5($meet, ['event_number' => 3]), 'ÖJM PI');

    $this->actingAs(admin_mesu())
        ->get(route('meets.scoring-group-restrictions', $meet))
        ->assertOk()
        ->assertSee('Medaillenwertung setzen')
        ->assertSeeInOrder(['ÖBSV Cup', 'teilweise (1 von 2)', 'ÖJM PI', 'ja']);
});

// ── Bewerbsformular ───────────────────────────────────────────────────────────

it('bietet im Bewerbsformular die Medaillenwertung und die Titel ÖJM und ÖBM an', function () {
    $event = makeEvent_p5(makeMeet_p5());
    group_mesu($event, 'ÖBSV Cup', ['awards_medals' => false]);

    $this->actingAs(admin_mesu())
        ->get(route('events.edit', $event))
        ->assertOk()
        ->assertSee('Medaillenwertung')
        ->assertSee('Österr. Jugendmeisterschaft')
        ->assertSee('Österr. B-Meisterschaft')
        ->assertSee('"awards_medals":"0"', false);
});

it('speichert die abgeschaltete Medaillenwertung aus dem Bewerbsformular', /** @throws JsonException */ function () {
    $event = makeEvent_p5(makeMeet_p5());

    $this->actingAs(admin_mesu())
        ->put(route('events.update', $event), [
            'stroke_type_id' => $event->stroke_type_id, 'session_number' => 1, 'gender' => 'M', 'round' => 'TIM',
            'distance' => 100, 'relay_count' => 1,
            'scoring_groups' => [
                ['name' => 'ÖBSV Cup', 'gender' => 'M', 'title' => '', 'awards_medals' => '0'],
                ['name' => 'ÖBM S01 - S10', 'gender' => 'M', 'title' => ScoringGroup::TITLE_B, 'awards_medals' => '1'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $groups = $event->scoringGroups()->get();
    expect($groups->pluck('awards_medals')->all())->toBe([false, true])
        ->and($groups[1]->title)->toBe(ScoringGroup::TITLE_B);
});

it('unterstellt die neuen Routen dem Schreibrecht für Veranstaltungen', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->keyBy(fn ($r) => $r->getName());

    expect($routes['meets.medal-rule.update']->gatherMiddleware())->toContain('module:meets,write')
        ->and($routes['meets.scoring-group-medals.update']->gatherMiddleware())->toContain('module:meets,write');
});

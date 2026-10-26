<?php

use App\Http\Controllers\MeetScoringGroupRestrictionsController;
use App\Models\AthleteLevelHistory;
use App\Models\Meet;
use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scoring-group-restrictions-p2');

// Pflege der Einschränkung nach Landesverband und Level (docs/specs/scoring-group-restrictions.md, Phase 2).
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php). makeMeet_p5(): LCM, 15.06.2025.

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_sgr2(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function group_sgr2(SwimEvent $event, string $name, int $order): ScoringGroup
{
    return ScoringGroup::create(['swim_event_id' => $event->id, 'name' => $name, 'gender' => 'A', 'sort_order' => $order]);
}

/** Pflichtfelder des Bewerbsformulars (ohne Wertungsgruppen). */
function eventForm_sgr2(SwimEvent $event): array
{
    return [
        'stroke_type_id' => $event->stroke_type_id, 'session_number' => 1, 'gender' => 'A', 'round' => 'TIM',
        'distance' => $event->distance, 'relay_count' => $event->relay_count,
    ];
}

function result_sgr2(Meet $meet, SwimEvent $event, ?string $association, int $points): Result
{
    $club = makeClub_p5();
    $club->update(['regional_association' => $association]);

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => makeAthlete_p5($club)->id,
        'club_id' => $club->id, 'swim_time' => 10000 - $points, 'points' => $points, 'sport_class' => 'S9',
    ]);
}

// ── Bewerbsformular ───────────────────────────────────────────────────────────

it('speichert Landesverband und Level im Bewerbsformular', function () {
    $event = makeEvent_p5(makeMeet_p5());

    $this->actingAs(admin_sgr2())
        ->put(route('events.update', $event), eventForm_sgr2($event) + ['scoring_groups' => [
            ['name' => 'LM Wien', 'gender' => 'A', 'regional_associations' => 'wbsv', 'regional_mode' => 'only', 'levels' => 'Talent, Elite Kader;B'],
            ['name' => 'Offen', 'gender' => 'A', 'regional_mode' => 'except'],
        ]])
        ->assertRedirect();

    $groups = $event->scoringGroups()->get();
    expect($groups[0]->regional_associations)->toBe('WBSV')
        ->and($groups[0]->regional_mode)->toBe(ScoringGroup::REGION_ONLY)
        ->and($groups[0]->levels)->toBe('B,Elite Kader,Talent')
        ->and($groups[1]->regional_associations)->toBeNull()
        ->and($groups[1]->regional_mode)->toBeNull();
});

it('bietet als Level die vergebenen Werte der Athleten und der Gruppen an', function () {
    $event = makeEvent_p5(makeMeet_p5());
    $club = makeClub_p5();
    makeAthlete_p5($club)->update(['level' => 'B']);
    $historic = makeAthlete_p5($club);
    AthleteLevelHistory::create(['athlete_id' => $historic->id, 'level' => 'Elite', 'changed_at' => '2024-01-01']);
    ScoringGroup::create(['swim_event_id' => $event->id, 'name' => 'Talente', 'gender' => 'A', 'sort_order' => 1, 'levels' => 'Talent']);

    expect(ScoringGroup::levelOptions([$event->scoringGroups()->value('levels')]))->toBe(['B', 'Elite', 'Talent']);

    $this->actingAs(admin_sgr2())
        ->get(route('events.edit', $event))
        ->assertOk()
        ->assertSee('value="Elite"', false)
        ->assertSee('value="Talent"', false);
});

it('speichert bei Staffeln kein Level', function () {
    $event = makeEvent_p5(makeMeet_p5(), ['relay_count' => 4]);

    $this->actingAs(admin_sgr2())
        ->put(route('events.update', $event), eventForm_sgr2($event) + ['scoring_groups' => [
            ['name' => 'Gäste', 'gender' => 'A', 'regional_associations' => 'WBSV', 'regional_mode' => 'except', 'levels' => 'B'],
        ]])
        ->assertRedirect();

    $group = $event->scoringGroups()->first();
    expect($group->regional_mode)->toBe(ScoringGroup::REGION_EXCEPT)
        ->and($group->levels)->toBeNull();
});

it('lehnt unbekannte Verbände und Verbände ohne Modus ab', function () {
    $event = makeEvent_p5(makeMeet_p5());

    $this->actingAs(admin_sgr2())
        ->put(route('events.update', $event), eventForm_sgr2($event) + ['scoring_groups' => [
            ['name' => 'LM', 'gender' => 'A', 'regional_associations' => 'WBSV,XYZ', 'regional_mode' => 'only'],
            ['name' => 'Gäste', 'gender' => 'A', 'regional_associations' => 'WBSV'],
        ]])
        ->assertSessionHasErrors([
            'scoring_groups.0.regional_associations', 'scoring_groups.1.regional_mode',
        ]);

    expect($event->scoringGroups()->count())->toBe(0);
});

it('übernimmt Landesverband und Level auf andere Bewerbe', function () {
    $meet = makeMeet_p5();
    $source = makeEvent_p5($meet, ['event_number' => 1]);
    $target = makeEvent_p5($meet, ['event_number' => 2]);
    ScoringGroup::create([
        'swim_event_id' => $source->id, 'name' => 'LM Wien', 'gender' => 'A', 'sort_order' => 1,
        'regional_associations' => 'WBSV', 'regional_mode' => ScoringGroup::REGION_ONLY, 'levels' => 'B',
    ]);

    $this->actingAs(admin_sgr2())->post(route('events.scoring-groups.copy', $source))->assertRedirect();

    $copy = $target->scoringGroups()->first();
    expect($copy->regional_associations)->toBe('WBSV')
        ->and($copy->regional_mode)->toBe(ScoringGroup::REGION_ONLY)
        ->and($copy->levels)->toBe('B');
});

// ── Sammelaktion ──────────────────────────────────────────────────────────────

it('zeigt die Wertungsgruppen der Veranstaltung auf der Sammelseite', function () {
    $meet = makeMeet_p5();
    $first = makeEvent_p5($meet, ['event_number' => 1]);
    $second = makeEvent_p5($meet, ['event_number' => 2]);
    group_sgr2($first, 'LM Wien SB 01 - 09', 1)->update(['regional_associations' => 'WBSV', 'regional_mode' => 'only']);
    group_sgr2($second, 'LM Wien SB 01 - 09', 1);
    group_sgr2($first, 'Gäste SB 01 - 15', 2);

    $this->actingAs(admin_sgr2())
        ->get(route('meets.scoring-group-restrictions', $meet))
        ->assertOk()
        ->assertSeeText('LM Wien SB 01 - 09')
        ->assertSeeText('Gäste SB 01 - 15')
        ->assertSeeText('nur WBSV / –');
});

it('setzt die Einschränkung an allen Gruppen mit dem Namensanfang', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    $relay = makeEvent_p5($meet, ['event_number' => 2, 'relay_count' => 4]);
    $lm = group_sgr2($event, 'LM Wien SB 01 - 09', 1);
    $lmYouth = group_sgr2($event, 'lm wien Jugend', 2);
    $guests = group_sgr2($event, 'Gäste', 3);
    $lmRelay = group_sgr2($relay, 'LM Wien Staffel', 1);

    $this->actingAs(admin_sgr2())
        ->put(route('meets.scoring-group-restrictions.update', $meet), [
            'prefix' => ' LM Wien', 'regional_mode' => 'only', 'regional_associations' => 'WBSV', 'levels' => 'B',
        ])
        ->assertRedirect(route('meets.scoring-group-restrictions', $meet))
        ->assertSessionHas('success', 'Einschränkung an 3 Wertungsgruppen in 2 Bewerben gesetzt, Plätze neu berechnet.');

    expect($lm->fresh()->restrictionLabel())->toBe('nur WBSV · Level B')
        ->and($lmYouth->fresh()->restrictionLabel())->toBe('nur WBSV · Level B')
        ->and($lmRelay->fresh()->restrictionLabel())->toBe('nur WBSV') // Staffel ohne Level
        ->and($guests->fresh()->restrictionLabel())->toBeNull();
});

it('rechnet nach der Sammelaktion die Plätze neu', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    group_sgr2($event, 'LM Wien', 1);
    group_sgr2($event, 'Gäste', 2);
    $salzburg = result_sgr2($meet, $event, 'SBSV', 700);
    $wien = result_sgr2($meet, $event, 'WBSV', 600);
    $admin = admin_sgr2();

    $this->actingAs($admin)->put(route('meets.scoring-group-restrictions.update', $meet), [
        'prefix' => 'LM Wien', 'regional_mode' => 'only', 'regional_associations' => 'WBSV',
    ]);
    $this->actingAs($admin)->put(route('meets.scoring-group-restrictions.update', $meet), [
        'prefix' => 'Gäste', 'regional_mode' => 'except', 'regional_associations' => 'WBSV',
    ]);

    // Ohne Einschränkung lag der Wiener in "LM Wien" auf Platz 2 hinter dem Salzburger.
    expect($wien->fresh()->place)->toBe(1)
        ->and($salzburg->fresh()->place)->toBe(1);
});

it('hebt die Einschränkung mit "keine Einschränkung" auf und meldet fehlende Treffer', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    $group = group_sgr2($event, 'Gäste', 1);
    $group->update(['regional_associations' => 'WBSV', 'regional_mode' => 'except', 'levels' => 'A']);

    $this->actingAs(admin_sgr2())
        ->put(route('meets.scoring-group-restrictions.update', $meet), [
            'prefix' => 'gäste', 'regional_mode' => MeetScoringGroupRestrictionsController::MODE_NONE,
        ])
        ->assertRedirect();

    expect($group->fresh()->restrictionLabel())->toBeNull();

    $this->actingAs(admin_sgr2())
        ->from(route('meets.scoring-group-restrictions', $meet))
        ->put(route('meets.scoring-group-restrictions.update', $meet), [
            'prefix' => 'LM Tirol', 'regional_mode' => 'only', 'regional_associations' => 'TBSV',
        ])
        ->assertRedirect(route('meets.scoring-group-restrictions', $meet))
        ->assertSessionHas('error', 'Keine Wertungsgruppe beginnt mit "LM Tirol".');
});

it('verlangt Landesverbände, wenn ein Modus gewählt ist', function () {
    $meet = makeMeet_p5();
    group_sgr2(makeEvent_p5($meet), 'LM Wien', 1);

    $this->actingAs(admin_sgr2())
        ->put(route('meets.scoring-group-restrictions.update', $meet), [
            'prefix' => 'LM Wien', 'regional_mode' => 'only', 'regional_associations' => '',
        ])
        ->assertSessionHasErrors(['regional_associations']);
});

// ── Anzeige ───────────────────────────────────────────────────────────────────

it('zeigt die Einschränkung in der Ergebnis-Sammelansicht', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    group_sgr2($event, 'LM Wien', 1)->update(['regional_associations' => 'WBSV', 'regional_mode' => 'only']);
    result_sgr2($meet, $event, 'WBSV', 600);

    $this->actingAs(admin_sgr2())
        ->get(route('meets.results-overview', $meet))
        ->assertOk()
        ->assertSeeText('nur WBSV')
        ->assertSee(route('meets.scoring-group-restrictions', $meet));
});

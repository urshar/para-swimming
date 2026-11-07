<?php

use App\Models\Meet;
use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scoring-group-order-copy-p1');

// Reihenfolge und Einzelkopie von Wertungsgruppen (docs/specs/scoring-groups.md).
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php). makeMeet_p5(): LCM, 15.06.2025.

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_sgoc(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function group_sgoc(SwimEvent $event, string $name, array $attrs = []): ScoringGroup
{
    return ScoringGroup::create([
        'swim_event_id' => $event->id, 'name' => $name, 'gender' => 'A',
        'sort_order' => $event->scoringGroups()->count() + 1,
        ...$attrs,
    ]);
}

/** Pflichtfelder des Bewerbsformulars plus Wertungsgruppen. */
function eventForm_sgoc(SwimEvent $event, array $groups): array
{
    return [
        'stroke_type_id' => $event->stroke_type_id, 'session_number' => 1, 'gender' => $event->gender, 'round' => 'TIM',
        'distance' => $event->distance, 'relay_count' => $event->relay_count, 'scoring_groups' => $groups,
    ];
}

function result_sgoc(Meet $meet, SwimEvent $event, string $class, int $points): Result
{
    $club = makeClub_p5();

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => makeAthlete_p5($club)->id,
        'club_id' => $club->id, 'swim_time' => 10000 - $points, 'points' => $points, 'sport_class' => $class,
    ]);
}

// ── Reihenfolge ───────────────────────────────────────────────────────────────

it('speichert die Gruppen in der abgeschickten Reihenfolge', function () {
    $event = makeEvent_p5(makeMeet_p5());
    group_sgoc($event, 'Erste');
    group_sgoc($event, 'Zweite');

    $this->actingAs(admin_sgoc())
        ->put(route('events.update', $event), eventForm_sgoc($event, [
            ['name' => 'Zweite', 'gender' => 'A'],
            ['name' => 'Erste', 'gender' => 'A'],
        ]))
        ->assertRedirect();

    $groups = $event->scoringGroups()->get();
    expect($groups->pluck('name')->all())->toBe(['Zweite', 'Erste'])
        ->and($groups->pluck('sort_order')->all())->toBe([1, 2]);
});

it('speichert nach dem Verschieben den Platz der neuen ersten Gruppe', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    group_sgoc($event, 'Alle');
    group_sgoc($event, 'Nur S9', ['sport_classes' => '9']);
    $faster = result_sgoc($meet, $event, 'S10', 800);
    $swimmer = result_sgoc($meet, $event, 'S9', 700);

    $this->actingAs(admin_sgoc())
        ->put(route('events.update', $event), eventForm_sgoc($event, [
            ['name' => 'Alle', 'gender' => 'A'],
            ['name' => 'Nur S9', 'gender' => 'A', 'sport_classes' => '9'],
        ]))
        ->assertRedirect();
    expect($swimmer->fresh()->place)->toBe(2);

    $this->actingAs(admin_sgoc())
        ->put(route('events.update', $event), eventForm_sgoc($event, [
            ['name' => 'Nur S9', 'gender' => 'A', 'sport_classes' => '9'],
            ['name' => 'Alle', 'gender' => 'A'],
        ]))
        ->assertRedirect();
    expect($swimmer->fresh()->place)->toBe(1)
        ->and($faster->fresh()->place)->toBe(1);
});

// ── Einzelkopie ───────────────────────────────────────────────────────────────

it('fügt die kopierte Gruppe hinten an und lässt vorhandene Gruppen stehen', function () {
    $meet = makeMeet_p5();
    $source = makeEvent_p5($meet, ['event_number' => 1]);
    $target = makeEvent_p5($meet, ['event_number' => 2]);
    $group = group_sgoc($source, 'ÖJM PI', ['gender' => 'M', 'sport_classes' => '1,2', 'age_max' => 18]);
    group_sgoc($source, 'Nicht kopieren');
    group_sgoc($target, 'Vorhanden', ['gender' => 'M']);

    $this->actingAs(admin_sgoc())
        ->post(route('scoring-groups.copy', $group), ['target_event_ids' => [$target->id]])
        ->assertRedirect(route('events.edit', $source))
        ->assertSessionHas('success', 'Wertungsgruppe "Herren – ÖJM PI" in 1 Bewerb kopiert.');

    $groups = $target->scoringGroups()->get();
    expect($groups->pluck('name')->all())->toBe(['Vorhanden', 'ÖJM PI'])
        ->and($groups->pluck('sort_order')->all())->toBe([1, 2])
        ->and($source->scoringGroups()->count())->toBe(2);
});

it('kopiert alle Einstellungen außer der LENEX-ID', function () {
    $meet = makeMeet_p5();
    $source = makeEvent_p5($meet, ['event_number' => 1, 'gender' => 'A']);
    $target = makeEvent_p5($meet, ['event_number' => 2, 'gender' => 'A']);
    $group = group_sgoc($source, 'LM Wien', [
        'gender' => 'F', 'sport_classes' => '14', 'age_min' => 10, 'age_max' => 18, 'regional_associations' => 'WBSV',
        'regional_mode' => ScoringGroup::REGION_ONLY, 'levels' => 'B', 'title' => ScoringGroup::TITLE_NATIONAL,
        'lenex_agegroup_id' => '4711',
    ]);

    $this->actingAs(admin_sgoc())->post(route('scoring-groups.copy', $group), ['target_event_ids' => [$target->id]]);

    $copy = $target->scoringGroups()->first();
    expect($copy->gender)->toBe('F')
        ->and($copy->sport_classes)->toBe('14')
        ->and($copy->age_min)->toBe(10)
        ->and($copy->age_max)->toBe(18)
        ->and($copy->regional_associations)->toBe('WBSV')
        ->and($copy->regional_mode)->toBe(ScoringGroup::REGION_ONLY)
        ->and($copy->levels)->toBe('B')
        ->and($copy->title)->toBe(ScoringGroup::TITLE_NATIONAL)
        ->and($copy->lenex_agegroup_id)->toBeNull();
});

it('überspringt Ziele mit gleichem Namen und Geschlecht, auch beim zweiten Kopieren', function () {
    $meet = makeMeet_p5();
    $source = makeEvent_p5($meet, ['event_number' => 1, 'gender' => 'A']);
    $withGroup = makeEvent_p5($meet, ['event_number' => 2, 'gender' => 'A']);
    $free = makeEvent_p5($meet, ['event_number' => 3, 'gender' => 'A']);
    $group = group_sgoc($source, 'ÖJM PI', ['gender' => 'M']);
    group_sgoc($withGroup, 'öjm pi', ['gender' => 'M']);
    $admin = admin_sgoc();

    $this->actingAs($admin)
        ->post(route('scoring-groups.copy', $group), ['target_event_ids' => [$withGroup->id, $free->id]])
        ->assertSessionHas('success', 'Wertungsgruppe "Herren – ÖJM PI" in 1 Bewerb kopiert. Übersprungen: Nr. 2 (Gruppe bereits vorhanden).');

    $this->actingAs($admin)
        ->post(route('scoring-groups.copy', $group), ['target_event_ids' => [$free->id]])
        ->assertSessionHas('error', 'Wertungsgruppe "Herren – ÖJM PI" wurde nicht kopiert. Übersprungen: Nr. 3 (Gruppe bereits vorhanden).');

    expect($withGroup->scoringGroups()->count())->toBe(1)
        ->and($free->scoringGroups()->count())->toBe(1);
});

it('wendet die Geschlechtsregel an', function () {
    $meet = makeMeet_p5();
    $openSource = makeEvent_p5($meet, ['event_number' => 1, 'gender' => 'A']);
    $women = makeEvent_p5($meet, ['event_number' => 2, 'gender' => 'F']);
    $men = makeEvent_p5($meet, ['event_number' => 3, 'gender' => 'M']);
    $herren = group_sgoc($openSource, 'Herren', ['gender' => 'M']);
    $alle = group_sgoc($openSource, 'Alle', ['gender' => 'A']);
    $admin = admin_sgoc();

    $this->actingAs($admin)
        ->post(route('scoring-groups.copy', $herren), ['target_event_ids' => [$women->id, $men->id]])
        ->assertSessionHas('success', 'Wertungsgruppe "Herren – Herren" in 1 Bewerb kopiert. Übersprungen: Nr. 2 (anderes Geschlecht).');
    $this->actingAs($admin)->post(route('scoring-groups.copy', $alle), ['target_event_ids' => [$women->id]]);

    expect($women->scoringGroups()->pluck('gender', 'name')->all())->toBe(['Alle' => 'F'])
        ->and($men->scoringGroups()->pluck('gender', 'name')->all())->toBe(['Herren' => 'M']);

    // Quelle mit festem Geschlecht: Das Ziel gibt das Geschlecht vor (wie beim Übernehmen aller Gruppen).
    $womenGroup = group_sgoc($women, 'Damen', ['gender' => 'F']);
    $this->actingAs($admin)->post(route('scoring-groups.copy', $womenGroup), ['target_event_ids' => [$men->id]]);
    expect($men->scoringGroups()->pluck('gender', 'name')->all())->toBe(['Herren' => 'M', 'Damen' => 'M']);
});

it('kopiert über die Klassenkategorie, aber nicht zwischen Einzel und Staffel oder in andere Veranstaltungen', function () {
    $meet = makeMeet_p5();
    $source = makeEvent_p5($meet, ['event_number' => 1]);
    $breast = makeEvent_p5($meet, ['event_number' => 2, 'stroke_type_id' => makeStrokeType_p5('BREAST')->id]);
    $relay = makeEvent_p5($meet, ['event_number' => 3, 'relay_count' => 4]);
    $foreign = makeEvent_p5(makeMeet_p5(), ['event_number' => 1]);
    $group = group_sgoc($source, 'Offen', ['gender' => 'M']);

    $this->actingAs(admin_sgoc())
        ->post(route('scoring-groups.copy', $group), ['target_event_ids' => [$breast->id, $relay->id, $foreign->id, $source->id]])
        ->assertSessionHas('success', 'Wertungsgruppe "Herren – Offen" in 1 Bewerb kopiert.');

    expect($breast->scoringGroups()->count())->toBe(1)
        ->and($relay->scoringGroups()->count())->toBe(0)
        ->and($foreign->scoringGroups()->count())->toBe(0)
        ->and($source->scoringGroups()->count())->toBe(1);
});

it('meldet einen Fehler ohne gültigen Zielbewerb', function () {
    $meet = makeMeet_p5();
    $source = makeEvent_p5($meet);
    $group = group_sgoc($source, 'Offen');

    $this->actingAs(admin_sgoc())
        ->post(route('scoring-groups.copy', $group))
        ->assertRedirect(route('events.edit', $source))
        ->assertSessionHas('error', 'Bitte mindestens einen Zielbewerb auswählen.');
});

it('berechnet Klassen und Plätze der Zielbewerbe neu', function () {
    $meet = makeMeet_p5();
    $source = makeEvent_p5($meet, ['event_number' => 1]);
    $target = makeEvent_p5($meet, ['event_number' => 2, 'sport_classes' => null]);
    $group = group_sgoc($source, 'S9', ['gender' => 'M', 'sport_classes' => '9']);
    $result = result_sgoc($meet, $target, 'S9', 700);

    $this->actingAs(admin_sgoc())->post(route('scoring-groups.copy', $group), ['target_event_ids' => [$target->id]]);

    expect($target->fresh()->sport_classes)->toBe('9')
        ->and($result->fresh()->place)->toBe(1);
});

it('verweigert das Kopieren ohne Schreibrecht für Wettkämpfe', function () {
    $meet = makeMeet_p5();
    $source = makeEvent_p5($meet, ['event_number' => 1]);
    $target = makeEvent_p5($meet, ['event_number' => 2]);
    $group = group_sgoc($source, 'Offen', ['gender' => 'M']);
    $user = User::factory()->create(['is_admin' => false, 'club_id' => null]);

    $this->actingAs($user)
        ->post(route('scoring-groups.copy', $group), ['target_event_ids' => [$target->id]])
        ->assertForbidden();

    expect($target->scoringGroups()->count())->toBe(0);
});

// ── Formular ──────────────────────────────────────────────────────────────────

it('bietet im Formular die Zielbewerbe gleicher Art mit Kategorie an', function () {
    $meet = makeMeet_p5();
    $source = makeEvent_p5($meet, ['event_number' => 1]);
    makeEvent_p5($meet, ['event_number' => 2, 'stroke_type_id' => makeStrokeType_p5('BREAST')->id]);
    makeEvent_p5($meet, ['event_number' => 3, 'relay_count' => 4]);
    $group = group_sgoc($source, 'Offen', ['gender' => 'M']);

    $response = $this->actingAs(admin_sgoc())->get(route('events.edit', $source))->assertOk();

    $targets = $response->viewData('copyTargets');
    expect($targets)->toHaveCount(1)
        ->and($targets[0]['category'])->toBe('SB')
        ->and($targets[0]['same_category'])->toBeFalse()
        ->and($targets[0]['without_groups'])->toBeTrue();
    $response->assertSee('Wertungsgruppe kopieren')
        ->assertSee(str_replace('/', '\/', route('scoring-groups.copy', $group)), false);
});

it('zeigt ohne möglichen Zielbewerb keinen Kopierdialog', function () {
    $source = makeEvent_p5(makeMeet_p5());
    group_sgoc($source, 'Offen');

    $this->actingAs(admin_sgoc())
        ->get(route('events.edit', $source))
        ->assertOk()
        ->assertDontSee('Wertungsgruppe kopieren');
});

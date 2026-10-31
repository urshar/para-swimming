<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\ForeignStart;
use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('club-permissions-p9');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Vereinsnutzer mit der Vorlage "Verein" (Ergebnisse lesen eigener Verein). */
function clubUser_cp9(Club $club): User
{
    return User::factory()->create(['club_id' => $club->id]);
}

function athlete_cp9(Club $club, string $lastName): Athlete
{
    $athlete = makeAthlete_p5($club);
    $athlete->update(['last_name' => $lastName]);

    return $athlete;
}

function result_cp9(Meet $meet, SwimEvent $event, Athlete $athlete, int $time): Result
{
    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id, 'sport_class' => 'S9', 'swim_time' => $time,
    ]);
}

// ── Ergebnisliste und Detailseite ─────────────────────────────────────────────

it('zeigt in der Ergebnisliste nur Ergebnisse eigener Athleten, ohne Bearbeiten', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    $own = result_cp9($meet, $event, athlete_cp9($club, 'Eigenathlet'), 3300);
    result_cp9($meet, $event, athlete_cp9(makeClub_p5(), 'Fremdathlet'), 3200);

    $otherMeet = Meet::create(['name' => 'Fremdmeet', 'nation_id' => makeNation_p5()->id, 'course' => 'LCM', 'start_date' => '2025-07-01']);
    result_cp9($otherMeet, makeEvent_p5($otherMeet), athlete_cp9(makeClub_p5(), 'Ganzfremd'), 3100);

    $this->actingAs(clubUser_cp9($club))->get(route('results.index'))
        ->assertOk()
        ->assertSee('Eigenathlet')
        ->assertDontSee('Fremdathlet')
        ->assertDontSee('Fremdmeet')
        ->assertDontSee(route('results.edit', $own))
        ->assertDontSee('Punkte neu berechnen');

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('results.index'))
        ->assertSee('Fremdathlet')
        ->assertSee(route('results.edit', $own));
});

it('verweigert die Detailseite eines fremden Ergebnisses', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    $own = result_cp9($meet, $event, athlete_cp9($club, 'Eigenathlet'), 3300);
    $foreign = result_cp9($meet, $event, athlete_cp9(makeClub_p5(), 'Fremdathlet'), 3200);
    $user = clubUser_cp9($club);

    $this->actingAs($user)->get(route('results.show', $own))
        ->assertOk()
        ->assertDontSee(route('results.edit', $own));
    $this->actingAs($user)->get(route('results.show', $foreign))->assertForbidden();
    $this->actingAs($user)->get(route('results.edit', $own))->assertForbidden();
});

it('zeigt Ergebnisse eines weggezogenen Athleten nicht mehr', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $athlete = athlete_cp9($club, 'Weggezogen');
    $result = result_cp9($meet, makeEvent_p5($meet), $athlete, 3300);

    $athlete->update(['club_id' => makeClub_p5()->id]);

    // Das Ergebnis bleibt für den alten Verein geschwommen, gehört aber nicht mehr zu seinen Athleten (P9-1).
    expect($result->fresh()->club_id)->toBe($club->id);
    $this->actingAs(clubUser_cp9($club))->get(route('results.show', $result))->assertForbidden();
});

// ── Sammelansicht und PDF ─────────────────────────────────────────────────────

it('zeigt in der Sammelansicht nur eigene Zeilen mit dem Platz im ganzen Feld', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    $own = result_cp9($meet, $event, athlete_cp9($club, 'Eigenathlet'), 3300);
    result_cp9($meet, $event, athlete_cp9(makeClub_p5(), 'Fremdathlet'), 3200);
    ForeignStart::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_name' => 'Swiss Club',
        'person_key' => 'x|y|', 'last_name' => 'Gast', 'first_name' => 'Gerda', 'swim_time' => 3000]);

    $response = $this->actingAs(clubUser_cp9($club))->get(route('meets.results-overview', $meet))
        ->assertOk()
        ->assertSee('Eigenathlet')
        ->assertDontSee('Fremdathlet')
        ->assertDontSee('Swiss Club')
        ->assertDontSee('Alle Vereine')
        ->assertDontSee(route('results.edit', $own))
        ->assertDontSee(route('meets.results.create', $meet))
        ->assertDontSee(route('meets.recalculate-points', $meet));

    $rows = $response->viewData('blocks')[$event->id]['groups'][0]['rows'];
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['result']->id)->toBe($own->id)
        ->and($rows[0]['place'])->toBe(2);

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('meets.results-overview', $meet))
        ->assertSee('Fremdathlet')
        ->assertSee('Swiss Club')
        ->assertSee(route('results.edit', $own));
});

it('zeigt Staffelergebnisse nur für Staffeln des eigenen Vereins', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $relayEvent = makeEvent_p5($meet, ['relay_count' => 4, 'distance' => 50]);
    $own = RelayResult::create(['meet_id' => $meet->id, 'swim_event_id' => $relayEvent->id, 'club_id' => $club->id,
        'name' => 'Eigenstaffel', 'gender' => 'M', 'swim_time' => 15000]);
    $foreign = RelayResult::create(['meet_id' => $meet->id, 'swim_event_id' => $relayEvent->id,
        'club_id' => makeClub_p5()->id, 'name' => 'Fremdstaffel', 'gender' => 'M', 'swim_time' => 14000]);
    $user = clubUser_cp9($club);

    $rows = collect($this->actingAs($user)->get(route('meets.results-overview', $meet))->assertOk()
        ->assertDontSee(route('relay-results.edit', $own))
        ->viewData('blocks')[$relayEvent->id]['groups'])->pluck('rows')->flatten(1);

    expect($rows->pluck('result.id')->all())->toBe([$own->id])
        ->and($own->isVisibleTo($user))->toBeTrue()
        ->and($foreign->isVisibleTo($user))->toBeFalse();
});

it('liefert das PDF der Ergebnisliste auch bei "eigener Verein"', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    result_cp9($meet, makeEvent_p5($meet), athlete_cp9($club, 'Eigenathlet'), 3300);

    $this->actingAs(clubUser_cp9($club))->get(route('meets.results-overview.pdf', $meet))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('blendet die Schaltfläche "Ergebnisse" auf der Wettkampfseite nach Leserecht ein', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();

    $this->actingAs(clubUser_cp9($club))->get(route('meets.show', $meet))
        ->assertSee(route('meets.results-overview', $meet));

    $coach = User::factory()->create(['club_id' => $club->id]);
    app(PermissionTemplateService::class)->applyTemplate($coach, RoleTemplate::Coach);
    $this->actingAs($coach->fresh())->get(route('meets.show', $meet))
        ->assertDontSee(route('meets.results-overview', $meet));
    $this->actingAs($coach->fresh())->get(route('results.index'))->assertForbidden();
});

// ── Meldungen ─────────────────────────────────────────────────────────────────

it('lässt die Vorlage "Trainer" Athleten des eigenen Vereins zum Melden auswählen', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $meet->update(['is_open' => true]);
    $event = makeEvent_p5($meet);
    $athlete = athlete_cp9($club, 'Meldbar');
    athlete_cp9(makeClub_p5(), 'Fremdathlet');

    $coach = User::factory()->create(['club_id' => $club->id]);
    app(PermissionTemplateService::class)->applyTemplate($coach, RoleTemplate::Coach);

    $this->actingAs($coach->fresh())
        ->getJson(route('club-entries.eligible-athletes', ['meet' => $meet, 'event_id' => $event->id]))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $athlete->id);
});

it('zeigt eigene Meldungen mit Leserecht auch bei geschlossenem Wettkampf, ohne Melden', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $meet->update(['is_open' => false]);

    $reader = User::factory()->create(['club_id' => $club->id]);
    app(PermissionTemplateService::class)->setPermissions($reader, [
        Module::Meets->value => ['read' => AccessScope::All, 'write' => AccessScope::None],
        Module::Entries->value => ['read' => AccessScope::Own, 'write' => AccessScope::None],
    ]);
    $reader = $reader->fresh();

    $this->actingAs($reader)->get(route('club-entries.index', $meet))
        ->assertOk()
        ->assertDontSee(route('club-entries.create', $meet));
    $this->actingAs($reader)->get(route('club-entries.relay.index', $meet))->assertOk();
    $this->actingAs($reader)->get(route('club-entries.create', $meet))->assertForbidden();
    $this->actingAs($reader)->get(route('meets.show', $meet))->assertSee(route('club-entries.index', $meet));
});

it('lässt den Verein bei nicht freigegebenem Wettkampf nur lesen, nicht melden', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $meet->update(['is_open' => false]);
    $user = clubUser_cp9($club);

    $this->actingAs($user)->get(route('club-entries.index', $meet))
        ->assertOk()
        ->assertDontSee(route('club-entries.create', $meet));
    $this->actingAs($user)->get(route('club-entries.create', $meet))->assertForbidden();

    $meet->update(['is_open' => true]);
    $this->actingAs($user)->get(route('club-entries.index', $meet))->assertSee(route('club-entries.create', $meet));
});

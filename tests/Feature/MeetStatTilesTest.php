<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meet-stat-tiles');

// Kacheln auf meets/show verlinken auf die Daten dahinter; Teilnehmerseite meets/{meet}/participants.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function user_mst(?Club $club): User
{
    return User::factory()->create(['is_admin' => $club === null, 'club_id' => $club?->id]);
}

/**
 * Veranstaltung mit zwei Vereinen: A meldet Anna einzeln und in einer Staffel (mit Berta), B hat nur ein Ergebnis von
 * Carl. Berta ist heute bei B, schwimmt hier aber in der Staffel von A.
 *
 * @return array{meet: Meet, a: Club, b: Club, anna: Athlete, berta: Athlete, carl: Athlete}
 */
function setup_mst(): array
{
    $meet = makeMeet_p5();
    $a = makeClub_p5();
    $b = makeClub_p5();
    $anna = makeAthlete_p5($a);
    $berta = makeAthlete_p5($b);
    $carl = makeAthlete_p5($b);
    $event = makeEvent_p5($meet);

    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $anna->id,
        'club_id' => $a->id]);
    $relay = RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => makeEvent_p5($meet, ['relay_count' => 4])->id,
        'club_id' => $a->id, 'relay_class' => 'S20', 'status' => 'pending']);
    RelayEntryMember::create(['relay_entry_id' => $relay->id, 'athlete_id' => $anna->id, 'position' => 1,
        'sport_class' => 'S9']);
    RelayEntryMember::create(['relay_entry_id' => $relay->id, 'athlete_id' => $berta->id, 'position' => 2,
        'sport_class' => 'S9']);
    Result::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $carl->id,
        'club_id' => $b->id, 'swim_time' => 9000]);

    return compact('meet', 'a', 'b', 'anna', 'berta', 'carl');
}

it('verlinkt für Admins alle Kacheln auf die Daten dahinter', function () {
    ['meet' => $meet] = setup_mst();

    $this->actingAs(user_mst(null))
        ->get(route('meets.show', $meet))
        ->assertOk()
        ->assertSee('href="#disziplinen"', false)
        ->assertSee('id="disziplinen"', false)
        ->assertSee('href="'.route('meets.entries-overview', $meet).'"', false)
        ->assertSee('href="'.route('meets.entries-overview', $meet).'#staffelmeldungen"', false)
        ->assertSee('href="'.route('meets.results-overview', $meet).'"', false)
        ->assertSee('aria-label="Teilnehmer: 3 anzeigen"', false)
        ->assertSee('aria-label="Clubs: 2 anzeigen"', false);
});

it('verlinkt für Vereinsnutzer die eigenen Meldungen und lässt Ergebnisse ohne Link', function () {
    ['meet' => $meet, 'a' => $a] = setup_mst();

    $this->actingAs(user_mst($a))
        ->get(route('meets.show', $meet))
        ->assertOk()
        ->assertSee('href="'.route('club-entries.index', $meet).'"', false)
        ->assertSee('href="'.route('club-entries.relay.index', $meet).'"', false)
        ->assertDontSee('href="'.route('meets.results-overview', $meet).'"', false)
        ->assertDontSee('aria-label="Ergebnisse:', false)
        ->assertSee('href="'.route('meets.participants', $meet).'"', false);
});

it('zeigt dieselben Teilnehmer wie die Kachel, mit dem Verein bei dieser Veranstaltung', function () {
    ['meet' => $meet, 'a' => $a, 'berta' => $berta] = setup_mst();

    $response = $this->actingAs(user_mst($a))->get(route('meets.participants', $meet))->assertOk();
    $rows = $response->viewData('rows');
    $bertaRow = $rows->firstWhere('athlete.id', $berta->id);

    expect($rows)->toHaveCount($meet->participantsCount())
        ->and($bertaRow['club']->id)->toBe($a->id)
        ->and($bertaRow['relays'])->toBe(1)
        ->and($bertaRow['entries'])->toBe(0);
    $response->assertSee('href="'.route('meets.show', $meet).'"', false);
});

it('filtert die Teilnehmer nach Verein und Name', function () {
    ['meet' => $meet, 'a' => $a, 'anna' => $anna, 'carl' => $carl] = setup_mst();
    $user = user_mst(null);

    $byClub = $this->actingAs($user)->get(route('meets.participants', [$meet, 'club_id' => $a->id]))->viewData('rows');
    $byName = $this->actingAs($user)
        ->get(route('meets.participants', [$meet, 'suche' => strtolower($carl->last_name)]))
        ->viewData('rows');

    expect($byClub->pluck('athlete.id')->sort()->values()->all())->toHaveCount(2)
        ->and($byClub->pluck('athlete.id')->all())->toContain($anna->id)
        ->and($byName->pluck('athlete.id')->all())->toBe([$carl->id]);
});

it('zeigt die Vereine mit Zählern wie die Kachel', function () {
    ['meet' => $meet, 'a' => $a, 'b' => $b] = setup_mst();

    $clubRows = $this->actingAs(user_mst($b))
        ->get(route('meets.participants', [$meet, 'ansicht' => 'vereine']))
        ->assertOk()
        ->assertSee('href="'.route('meets.participants', [$meet, 'club_id' => $a->id]).'"', false)
        ->viewData('clubRows')
        ->keyBy(fn (array $row) => $row['club']->id);

    expect($clubRows)->toHaveCount($meet->participatingClubsCount())
        ->and($clubRows[$a->id]['athletes'])->toBe(2)
        ->and($clubRows[$a->id]['entries'])->toBe(1)
        ->and($clubRows[$a->id]['relays'])->toBe(1)
        ->and($clubRows[$b->id]['athletes'])->toBe(1)
        ->and($clubRows[$b->id]['results'])->toBe(1);
});

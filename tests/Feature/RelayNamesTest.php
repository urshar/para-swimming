<?php

use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\LenexExportService;
use App\Services\MeetEntryListService;
use App\Support\RelayNames;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relay-names');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeEvent_p5(). Club::display_name ist dort der short_name ("TV<n>").

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function openMeet_rn(): Meet
{
    $meet = makeMeet_p5();
    $meet->update(['start_date' => now()->addDays(30)->toDateString(), 'is_open' => true]);

    return $meet;
}

function relayEvent_rn(Meet $meet, int $number = 1): SwimEvent
{
    return makeEvent_p5($meet, ['relay_count' => 4, 'event_number' => $number]);
}

function relay_rn(Meet $meet, SwimEvent $event, Club $club, ?string $name = null): RelayEntry
{
    return RelayEntry::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'club_id' => $club->id,
        'name' => $name,
        'relay_class' => 'S20',
        'status' => 'pending',
    ]);
}

function clubUser_rn(Club $club): User
{
    return User::factory()->create(['is_admin' => false, 'club_id' => $club->id]);
}

/**
 * LENEX-Export für den Test. build() deklariert eine DOMException; sie wird hier einmalig abgefangen und als
 * RuntimeException weitergereicht (Muster wie buildLenex_p7() in LenexRelayExportTest).
 */
function lenex_rn(Meet $meet): string
{
    try {
        return (new LenexExportService)->build($meet, 'entries');
    } catch (DOMException $e) {
        throw new RuntimeException('LENEX-Export fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

/** @return array<int, string> */ function names_rn(RelayEntry ...$relays): array
{
    return RelayNames::for(RelayEntry::with('club')->whereIn('id', collect($relays)->pluck('id'))->get());
}

// ── Namenslogik ───────────────────────────────────────────────────────────────

it('nimmt bei einer einzelnen unbenannten Staffel den Vereinsnamen ohne Nummer', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $relay = relay_rn($meet, relayEvent_rn($meet), $club);

    expect(names_rn($relay))->toBe([$relay->id => $club->display_name]);
});

it('nummeriert mehrere unbenannte Staffeln desselben Vereins im selben Bewerb nach Anlage', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = relayEvent_rn($meet);
    $first = relay_rn($meet, $event, $club);
    $second = relay_rn($meet, $event, $club);

    expect(names_rn($second, $first))->toBe([
        $first->id => $club->display_name.' 1',
        $second->id => $club->display_name.' 2',
    ]);
});

it('zählt nur unbenannte Staffeln und nimmt sonst den eigenen Namen', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = relayEvent_rn($meet);
    $a = relay_rn($meet, $event, $club);
    $named = relay_rn($meet, $event, $club, 'Team Kärnten');
    $b = relay_rn($meet, $event, $club);

    expect(names_rn($a, $named, $b))->toBe([
        $a->id => $club->display_name.' 1',
        $named->id => 'Team Kärnten',
        $b->id => $club->display_name.' 2',
    ]);
});

it('bleibt ohne Nummer, wenn neben der unbenannten nur benannte Staffeln existieren', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = relayEvent_rn($meet);
    relay_rn($meet, $event, $club, 'Team Kärnten');
    $unnamed = relay_rn($meet, $event, $club);

    expect(names_rn($unnamed))->toBe([$unnamed->id => $club->display_name]);
});

it('nummeriert je Bewerb und je Verein getrennt', function () {
    $meet = makeMeet_p5();
    $clubA = makeClub_p5();
    $clubB = makeClub_p5();
    $ev1 = relayEvent_rn($meet);
    $ev2 = relayEvent_rn($meet, 2);
    $a1 = relay_rn($meet, $ev1, $clubA);
    $a2 = relay_rn($meet, $ev1, $clubA);
    $aOther = relay_rn($meet, $ev2, $clubA);   // einzige in Bewerb 2
    $b = relay_rn($meet, $ev1, $clubB);        // einzige von Verein B

    expect(names_rn($a1, $a2, $aOther, $b))->toBe([
        $a1->id => $clubA->display_name.' 1',
        $a2->id => $clubA->display_name.' 2',
        $aOther->id => $clubA->display_name,
        $b->id => $clubB->display_name,
    ]);
});

it('zählt auch in einer Teilauswahl mit allen Geschwister-Staffeln', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = relayEvent_rn($meet);
    relay_rn($meet, $event, $club);
    $second = relay_rn($meet, $event, $club);

    // Nur die zweite übergeben (z. B. paginierte/gefilterte Liste) — Nummer bleibt 2.
    expect(names_rn($second))->toBe([$second->id => $club->display_name.' 2']);
});

// ── Formular: Speichern + Validierung ─────────────────────────────────────────

it('speichert einen frei vergebenen Staffelnamen beim Anlegen und Bearbeiten', function () {
    $meet = openMeet_rn();
    $club = makeClub_p5();
    $event = relayEvent_rn($meet);
    $user = clubUser_rn($club);

    $this->actingAs($user)
        ->post(route('club-entries.relay.store', $meet), ['swim_event_id' => $event->id, 'name' => 'Team Kärnten'])
        ->assertRedirect(route('club-entries.relay.index', $meet));

    $relay = RelayEntry::where('club_id', $club->id)->sole();
    expect($relay->name)->toBe('Team Kärnten');

    $this->actingAs($user)
        ->put(route('club-entries.relay.update', [$meet, $relay]), ['name' => 'Team Süd'])
        ->assertRedirect(route('club-entries.relay.index', $meet));
    expect($relay->fresh()->name)->toBe('Team Süd');

    // Leeren = zurück zum automatischen Namen.
    $this->actingAs($user)
        ->put(route('club-entries.relay.update', [$meet, $relay]), ['name' => ''])
        ->assertRedirect(route('club-entries.relay.index', $meet));
    expect($relay->fresh()->name)->toBeNull();
});

it('lehnt einen doppelten Namen innerhalb desselben Vereins und Bewerbs ab', function () {
    $meet = openMeet_rn();
    $club = makeClub_p5();
    $event = relayEvent_rn($meet);
    relay_rn($meet, $event, $club, 'Team Kärnten');

    $this->actingAs(clubUser_rn($club))
        ->post(route('club-entries.relay.store', $meet), ['swim_event_id' => $event->id, 'name' => 'Team Kärnten'])
        ->assertSessionHasErrors(['name' => 'Dieser Verein hat in diesem Bewerb bereits eine Staffel mit diesem Namen.']);

    expect(RelayEntry::where('club_id', $club->id)->count())->toBe(1);
});

it('erlaubt denselben Namen in einem anderen Bewerb, bei einem anderen Verein und beim unveränderten Speichern', function () {
    $meet = openMeet_rn();
    $club = makeClub_p5();
    $otherClub = makeClub_p5();
    $event = relayEvent_rn($meet);
    $otherEvent = relayEvent_rn($meet, 2);
    $own = relay_rn($meet, $event, $club, 'Team Kärnten');
    relay_rn($meet, $event, $otherClub, 'Team Kärnten');

    $user = clubUser_rn($club);
    $this->actingAs($user)
        ->post(route('club-entries.relay.store', $meet), ['swim_event_id' => $otherEvent->id, 'name' => 'Team Kärnten'])
        ->assertRedirect(route('club-entries.relay.index', $meet));
    $this->actingAs($user)
        ->put(route('club-entries.relay.update', [$meet, $own]), ['name' => 'Team Kärnten'])
        ->assertRedirect(route('club-entries.relay.index', $meet));
});

it('begrenzt den Staffelnamen auf 50 Zeichen', function () {
    $meet = openMeet_rn();
    $club = makeClub_p5();

    $this->actingAs(clubUser_rn($club))
        ->post(route('club-entries.relay.store', $meet), ['swim_event_id' => relayEvent_rn($meet)->id, 'name' => str_repeat('x', 51)])
        ->assertSessionHasErrors('name');
});

it('zeigt das Namensfeld im Anlege- und Bearbeiten-Formular', function () {
    $meet = openMeet_rn();
    $club = makeClub_p5();
    $relay = relay_rn($meet, relayEvent_rn($meet), $club, 'Team Kärnten');
    $user = clubUser_rn($club);

    $this->actingAs($user)->get(route('club-entries.relay.create', $meet))->assertOk()
        ->assertSee('Staffelname')
        ->assertSee('name="name"', false);
    $this->actingAs($user)->get(route('club-entries.relay.edit', [$meet, $relay]))->assertOk()
        ->assertSee('value="Team Kärnten"', false);
});

// ── Anzeige ───────────────────────────────────────────────────────────────────

it('zeigt die Anzeigenamen in "Alle Meldungen", im Cockpit und in der Vereinsliste', function () {
    $meet = openMeet_rn();
    $club = makeClub_p5();
    $event = relayEvent_rn($meet);
    relay_rn($meet, $event, $club);
    relay_rn($meet, $event, $club);
    relay_rn($meet, $event, $club, 'Team Kärnten');
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);

    foreach ([
        route('meets.entries-overview', $meet),
        route('relay-entries.index'),
        route('club-entries.relay.index', ['meet' => $meet, 'club_id' => $club->id]),
    ] as $url) {
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee($club->display_name.' 1')
            ->assertSee($club->display_name.' 2')
            ->assertSee('Team Kärnten');
    }
});

it('verwendet die Anzeigenamen in beiden PDF-Meldelisten', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $ev1 = relayEvent_rn($meet);
    $ev2 = relayEvent_rn($meet, 2);
    relay_rn($meet, $ev1, $club);
    relay_rn($meet, $ev1, $club);
    relay_rn($meet, $ev2, $club);   // einzige in Bewerb 2 → ohne Nummer (früher "... 3")

    $lists = app(MeetEntryListService::class);

    $byName = $lists->byName($meet)->first()['clubs']->first()['relays']->pluck('name')->all();
    $byEvent = $lists->byEvent($meet)->first()['events']
        ->flatMap(fn (array $ev) => $ev['relays']->pluck('name'))->all();

    $n = $club->display_name;
    expect($byName)->toBe([$n.' 1', $n.' 2', $n])
        ->and($byEvent)->toBe([$n.' 1', $n.' 2', $n]);
});

it('exportiert den Anzeigenamen als name-Attribut am LENEX-RELAY', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = relayEvent_rn($meet);
    relay_rn($meet, $event, $club);
    relay_rn($meet, $event, $club, 'Team Kärnten');
    relay_rn($meet, $event, $club);
    // Der Export listet nur Vereine, die der Veranstaltung zugeordnet sind.
    $meet->clubs()->attach($club->id);

    $xml = simplexml_load_string(lenex_rn($meet));
    $relays = $xml->MEETS->MEET->CLUBS->CLUB->RELAYS->RELAY;

    $pairs = [];
    foreach ($relays as $relay) {
        $pairs[(string) $relay['number']] = (string) $relay['name'];
    }

    expect($pairs)->toBe([
        '1' => $club->display_name.' 1',
        '2' => 'Team Kärnten',
        '3' => $club->display_name.' 2',
    ]);
});

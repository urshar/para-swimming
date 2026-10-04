<?php

use App\Models\Athlete;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\LenexExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-export-clubs');

// Regression: Der Export nahm die Vereine nur aus meet_club, die beim Melden und beim
// manuellen Erfassen von Ergebnissen nicht befüllt wird. Es kam nur die Struktur heraus.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Veranstaltung mit Verein, Athlet (S9), Einzel- und Staffelbewerb, ohne meet_club-Eintrag. */
function setup_lxc(): array
{
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    $relayEvent = makeEvent_p5($meet, ['event_number' => 2, 'relay_count' => 4]);

    return [$meet, $club, $athlete, $event, $relayEvent];
}

function result_lxc(Meet $meet, SwimEvent $event, Athlete $athlete): Result
{
    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => 65000,
        'sport_class' => 'S9',
    ]);
}

/** LENEX-Export (DOMException → RuntimeException). */
function lenex_lxc(Meet $meet, string $type): SimpleXMLElement
{
    try {
        return simplexml_load_string((new LenexExportService)->build($meet, $type));
    } catch (DOMException $e) {
        throw new RuntimeException('LENEX-Export fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

/** @return list<string> Vereinsnamen im Export, in Reihenfolge. */
function clubNames_lxc(SimpleXMLElement $xml): array
{
    $names = [];
    foreach ($xml->MEETS->MEET->CLUBS->CLUB as $club) {
        $names[] = (string) $club['name'];
    }

    return $names;
}

// ── Export ────────────────────────────────────────────────────────────────────

it('exportiert Meldungen mit Verein, Athlet und Staffel ohne meet_club-Eintrag', function () {
    [$meet, $club, $athlete, $event, $relayEvent] = setup_lxc();
    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id]);
    $relay = RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => $relayEvent->id, 'club_id' => $club->id]);
    RelayEntryMember::create(['relay_entry_id' => $relay->id, 'athlete_id' => $athlete->id, 'position' => 1, 'sport_class' => 'S9']);

    $clubXml = lenex_lxc($meet, 'entries')->MEETS->MEET->CLUBS->CLUB;

    expect($meet->clubs()->count())->toBe(0)
        ->and((string) $clubXml['name'])->toBe($club->name)
        ->and((string) $clubXml->ATHLETES->ATHLETE['lastname'])->toBe($athlete->last_name)
        ->and($clubXml->ATHLETES->ATHLETE->ENTRIES->ENTRY->count())->toBe(1)
        ->and($clubXml->RELAYS->RELAY->count())->toBe(1);
});

it('exportiert manuell erfasste Ergebnisse ohne meet_club-Eintrag', function () {
    [$meet, $club, $athlete, $event] = setup_lxc();
    result_lxc($meet, $event, $athlete);

    $clubXml = lenex_lxc($meet, 'results')->MEETS->MEET->CLUBS->CLUB;

    expect((string) $clubXml['name'])->toBe($club->name)
        ->and($clubXml->ATHLETES->ATHLETE->RESULTS->RESULT->count())->toBe(1);
});

it('nimmt in den Meldungs-Export nur Vereine mit Meldungen und in den Ergebnis-Export nur Vereine mit Ergebnissen', function () {
    [$meet, $club, $athlete, $event] = setup_lxc();
    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id]);
    $otherClub = makeClub_p5();
    result_lxc($meet, $event, makeAthlete_p5($otherClub));

    expect(clubNames_lxc(lenex_lxc($meet, 'entries')))->toBe([$club->name])
        ->and(clubNames_lxc(lenex_lxc($meet, 'results')))->toBe([$otherClub->name]);
});

it('behält per LENEX-Import zugeordnete Vereine und listet jeden Verein nur einmal, alphabetisch', function () {
    [$meet, $club, $athlete, $event] = setup_lxc();
    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id]);
    $importedClub = makeClub_p5();
    $importedClub->update(['name' => 'Aaa Importverein']);
    $meet->clubs()->attach([$importedClub->id, $club->id]);

    expect(clubNames_lxc(lenex_lxc($meet, 'entries')))->toBe(['Aaa Importverein', $club->name]);
});

// ── Veranstaltungsseite ───────────────────────────────────────────────────────

it('zeigt unter "Teilnehmende Vereine" die Vereine aus Meldungen und Ergebnissen', function () {
    [$meet, $club, $athlete, $event] = setup_lxc();
    $resultClub = makeClub_p5();
    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id]);
    result_lxc($meet, $event, makeAthlete_p5($resultClub));

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('meets.show', $meet))
        ->assertOk()
        ->assertSeeInOrder(['Teilnehmende Vereine', $club->display_name, $resultClub->display_name]);
});

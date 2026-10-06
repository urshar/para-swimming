<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Result;
use App\Models\User;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('lenex-import-review');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_lir(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function athlete_lir(Club $club, string $first, string $last, string $birthDate, ?string $license): Athlete
{
    return Athlete::create([
        'first_name' => $first,
        'last_name' => $last,
        'birth_date' => $birthDate,
        'gender' => 'F',
        'license' => $license,
        'nation_id' => makeNation_p5()->id,
        'club_id' => $club->id,
    ]);
}

/**
 * Ergebnisdatei: bekannter Verein mit Angerer (Lizenz ohne Leerzeichen), Singer (Tippfehler im Vornamen, keine
 * Lizenz), Huber (Geburtsdatum weicht ab) und Weg (wird übersprungen); dazu ein unbekannter Verein mit Neu.
 */
function resultXml_lir(Club $club): string
{
    $athlete = fn (int $id, string $first, string $last, string $birth, string $license, int $resultId) => '<ATHLETE athleteid="'.$id
        .'" firstname="'.$first.'" lastname="'.$last.'" birthdate="'.$birth.'" gender="F" license="'.$license.'">'
        .'<RESULTS><RESULT eventid="1059" resultid="'.$resultId.'" swimtime="00:00:40.00" /></RESULTS></ATHLETE>';

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Klärungstest" city="Salzburg" nation="AUT" course="SCM" startdate="2025-03-01">
<SESSIONS><SESSION number="1" date="2025-03-01"><EVENTS>
  <EVENT eventid="1059" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" gender="F" name="Damen" handicap="1,2,3,4,5,6,7,8,9,10,11,12,13,14" /></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS>
  <CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>'
        .$athlete(1, 'Daniela', 'Angerer', '2000-06-17', 'W-1653', 501)
        .$athlete(2, 'Domink', 'Singer', '2003-01-01', '', 502)
        .$athlete(3, 'Anna', 'Huber', '2001-06-17', '', 503)
        .$athlete(4, 'Wanda', 'Weg', '1999-01-01', '', 504).'
  </ATHLETES></CLUB>
  <CLUB name="SV Unbekannt" code="SVU" nation="AUT"><ATHLETES>'
        .$athlete(5, 'Nora', 'Neu', '2005-02-02', '', 505).'
  </ATHLETES></CLUB>
</CLUBS></MEET></MEETS></LENEX>';
}

/** Lädt die Datei hoch, wählt "neue Veranstaltung" und liefert die Antwort des Import-Laufs. */
function uploadAndRun_lir(User $admin, string $xml): TestResponse
{
    $upload = test()->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('ergebnisse.lef', $xml),
    ]);
    parse_str((string) parse_url($upload->headers->get('Location'), PHP_URL_QUERY), $query);

    return test()->actingAs($admin)->post(route('lenex.import.run'), ['import_session' => $query['session'], 'meet_id' => '']);
}

function sessionKey_lir(TestResponse $response): string
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['session'];
}

/** Index des unbekannten Athleten in der Import-Session (Reihenfolge wie auf der Klärungsseite). */
function athleteIndex_lir(string $sessionKey, string $lastName): int
{
    return collect(session($sessionKey)['unresolved_athletes'])->search(fn (array $a) => $a['last_name'] === $lastName);
}

// ── Abgleich und Klärung ──────────────────────────────────────────────────────

it('erkennt Lizenzen unabhängig von Leerzeichen', function () {
    $club = makeClub_p5();
    $athlete = athlete_lir($club, 'Daniela', 'Angerer', '2000-06-07', 'W - 1653');
    $xml = simplexml_load_string('<ATHLETE athleteid="1" firstname="Daniela" lastname="Angerer" birthdate="2000-06-17" gender="F" license="W-1653" />');

    $resolved = (new LenexResolverService)->resolveAthlete($xml, $club->id, makeNation_p5()->id);

    expect($resolved?->id)->toBe($athlete->id);
});

it('zeigt unbekannte Vereine mit Vorschlägen und bestehenden Vereinen zur Auswahl', function () {
    Storage::fake('local');
    makeStrokeType_p5();
    $club = makeClub_p5();
    Club::create(['name' => 'SV Unbekannt Salzburg', 'code' => 'SVB', 'nation_id' => makeNation_p5()->id]);

    $run = uploadAndRun_lir(admin_lir(), resultXml_lir($club));

    $run->assertRedirect();
    test()->get($run->headers->get('Location'))
        ->assertOk()
        ->assertSee('Unbekannte Vereine (1)')
        ->assertSee('SV Unbekannt')
        ->assertSee('Vorschlag — gleicher/ähnlicher Name oder Code')
        ->assertSee('Bestehendem Verein zuordnen')
        ->assertSee($club->name);
});

it('ordnet Vereine und Athleten bestehenden Datensätzen zu, legt neue an und überspringt', function () {
    Storage::fake('local');
    makeStrokeType_p5();
    $admin = admin_lir();
    $club = makeClub_p5();
    $otherClub = makeClub_p5();
    $angerer = athlete_lir($club, 'Daniela', 'Angerer', '2000-06-07', 'W - 1653');
    $singer = athlete_lir($club, 'Dominik', 'Singer', '2003-01-01', null);
    $huber = athlete_lir($club, 'Anna', 'Huber', '2001-06-07', null);

    $clubSession = sessionKey_lir(uploadAndRun_lir($admin, resultXml_lir($club)));
    $clubStep = test()->actingAs($admin)->post(route('lenex.import.resolve-clubs'), [
        'import_session' => $clubSession,
        'clubs' => [['selection' => (string) $otherClub->id]],
    ]);

    $athleteSession = sessionKey_lir($clubStep);
    test()->get(route('lenex.import.review', ['session' => $athleteSession]))
        ->assertOk()
        ->assertSee('Unbekannte Athleten (4)')
        ->assertSee('Jahrgang-Treffer vorbelegt')
        ->assertSee('Bestehendem Athleten zuordnen (Nachname S…)');

    $selection = fn (string $lastName, string $value) => [athleteIndex_lir($athleteSession, $lastName) => ['selection' => $value]];
    test()->actingAs($admin)->post(route('lenex.import.resolve-athletes'), [
        'import_session' => $athleteSession,
        'athletes' => $selection('Singer', (string) $singer->id)
            + $selection('Huber', (string) $huber->id)
            + $selection('Neu', 'new')
            + $selection('Weg', 'skip'),
    ])->assertRedirect(route('meets.index'));

    $neu = Athlete::where('last_name', 'Neu')->first();
    expect(Meet::count())->toBe(1)
        ->and(Result::count())->toBe(4)
        ->and(Result::pluck('athlete_id')->sort()->values()->all())
        ->toBe(collect([$angerer->id, $singer->id, $huber->id, $neu->id])->sort()->values()->all())
        ->and($neu->club_id)->toBe($otherClub->id)
        ->and($neu->birth_date->format('Y-m-d'))->toBe('2005-02-02')
        ->and(Athlete::where('last_name', 'Weg')->exists())->toBeFalse()
        ->and(Club::where('name', 'SV Unbekannt')->exists())->toBeFalse()
        ->and($singer->fresh()->first_name)->toBe('Dominik');
});

it('legt einen unbekannten Verein auf Wunsch neu an', function () {
    Storage::fake('local');
    makeStrokeType_p5();
    $admin = admin_lir();
    $club = makeClub_p5();
    athlete_lir($club, 'Daniela', 'Angerer', '2000-06-07', 'W - 1653');

    $clubSession = sessionKey_lir(uploadAndRun_lir($admin, resultXml_lir($club)));
    test()->actingAs($admin)->post(route('lenex.import.resolve-clubs'), [
        'import_session' => $clubSession,
        'clubs' => [['selection' => 'new']],
    ]);

    $created = Club::where('name', 'SV Unbekannt')->first();
    expect($created)->not->toBeNull()
        ->and($created->code)->toBe('SVU');
});

// ── Rahmenbewerbe (nicht gewertet) ────────────────────────────────────────────

/** Bewerb 1 mit Klassen, Bewerb 2 "Offen" ohne Klassenangabe; Angerer schwimmt beide, Schnupper nur Bewerb 2. */
function trialXml_lir(Club $club): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Schnuppertest" city="Innsbruck" nation="AUT" course="SCM" startdate="2025-04-05">
<SESSIONS><SESSION number="1" date="2025-04-05"><EVENTS>
  <EVENT eventid="11" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" gender="F" name="Damen" handicap="14" /></AGEGROUPS></EVENT>
  <EVENT eventid="22" number="2" round="TIM"><SWIMSTYLE distance="25" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="2" name="Offen" /></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>
  <ATHLETE athleteid="1" firstname="Daniela" lastname="Angerer" birthdate="2000-06-07" gender="F" license="W-1653"><RESULTS>
    <RESULT eventid="11" resultid="1" swimtime="00:00:40.00" />
    <RESULT eventid="22" resultid="2" swimtime="00:00:20.00" />
  </RESULTS></ATHLETE>
  <ATHLETE athleteid="2" firstname="Sam" lastname="Schnupper" birthdate="2015-01-01" gender="M"><RESULTS>
    <RESULT eventid="22" resultid="3" swimtime="00:00:30.00" />
  </RESULTS></ATHLETE>
</ATHLETES></CLUB></CLUBS></MEET></MEETS></LENEX>';
}

it('fragt Bewerbe ohne Klassenangabe ab und importiert für Rahmenbewerbe weder Ergebnisse noch Schwimmer', function () {
    Storage::fake('local');
    makeStrokeType_p5();
    $admin = admin_lir();
    $club = makeClub_p5();
    $angerer = athlete_lir($club, 'Daniela', 'Angerer', '2000-06-07', 'W - 1653');

    $run = uploadAndRun_lir($admin, trialXml_lir($club));
    test()->get($run->headers->get('Location'))
        ->assertOk()
        ->assertSee('Bewerbe ohne Wertungsklassen (1)')
        ->assertSee('Bewerb 2 – 25 m FREE')
        ->assertDontSee('Bewerb 1 –');

    test()->actingAs($admin)->post(route('lenex.import.resolve-events'), [
        'import_session' => sessionKey_lir($run),
        'unscored_events' => ['2'],
    ])->assertRedirect(route('meets.index'));

    $meet = Meet::sole();
    expect($meet->swimEvents()->where('event_number', 2)->sole()->is_scored)->toBeFalse()
        ->and($meet->swimEvents()->where('event_number', 1)->sole()->is_scored)->toBeTrue()
        ->and(Result::pluck('athlete_id')->all())->toBe([$angerer->id])
        ->and(Result::sole()->swimEvent->event_number)->toBe(1)
        ->and(Athlete::where('last_name', 'Schnupper')->exists())->toBeFalse();
});

it('fragt beim Nachimport nicht erneut und hält Rahmenbewerbe weiter außen vor', function () {
    Storage::fake('local');
    makeStrokeType_p5();
    $admin = admin_lir();
    $club = makeClub_p5();
    athlete_lir($club, 'Daniela', 'Angerer', '2000-06-07', 'W - 1653');
    $run = uploadAndRun_lir($admin, trialXml_lir($club));
    test()->actingAs($admin)->post(route('lenex.import.resolve-events'), [
        'import_session' => sessionKey_lir($run),
        'unscored_events' => ['2'],
    ]);

    $upload = test()->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('nochmal.lef', trialXml_lir($club)),
    ]);
    test()->actingAs($admin)->post(route('lenex.import.run'), [
        'import_session' => sessionKey_lir($upload),
        'meet_id' => (string) Meet::value('id'),
    ])->assertRedirect(route('meets.index'));

    expect(Result::count())->toBe(1)
        ->and(Athlete::where('last_name', 'Schnupper')->exists())->toBeFalse();
});

it('importiert nicht angekreuzte Bewerbe ohne Klassenangabe normal', function () {
    Storage::fake('local');
    makeStrokeType_p5();
    $admin = admin_lir();
    $club = makeClub_p5();
    athlete_lir($club, 'Daniela', 'Angerer', '2000-06-07', 'W - 1653');

    $run = uploadAndRun_lir($admin, trialXml_lir($club));
    $events = test()->actingAs($admin)->post(route('lenex.import.resolve-events'), ['import_session' => sessionKey_lir($run)]);

    expect($events->headers->get('Location'))->toContain('/lenex/import/review')
        ->and(Result::count())->toBe(2)
        ->and(collect(session(sessionKey_lir($events))['unresolved_athletes'])->pluck('last_name')->all())->toBe(['Schnupper']);
});

it('löscht beim Kennzeichnen als Rahmenbewerb vorhandene Ergebnisse erst nach Bestätigung', function () {
    makeStrokeType_p5();
    $admin = admin_lir();
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['event_number' => 2, 'distance' => 25]);
    $athlete = athlete_lir($club, 'Elias', 'Novak', '2010-01-01', null);
    Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'swim_time' => 3483,
    ]);
    $form = [
        'stroke_type_id' => $event->stroke_type_id, 'event_number' => 2, 'session_number' => 1, 'gender' => 'M',
        'round' => 'TIM', 'distance' => 25, 'relay_count' => 1, 'is_scored' => '0',
    ];

    test()->actingAs($admin)->put(route('events.update', $event), $form)
        ->assertSessionHasErrors('confirm_delete_results');
    expect($event->fresh()->is_scored)->toBeTrue()
        ->and(Result::count())->toBe(1);

    test()->actingAs($admin)->put(route('events.update', $event), $form + ['confirm_delete_results' => '1'])
        ->assertRedirect(route('meets.show', $meet));
    expect($event->fresh()->is_scored)->toBeFalse()
        ->and(Result::count())->toBe(0);
});

it('zählt Bewerbe mit Gruppe ohne Klassen als eingerichtet und Rahmenbewerbe gar nicht', function () {
    makeStrokeType_p5();
    $admin = admin_lir();
    $meet = makeMeet_p5();
    $open = makeEvent_p5($meet, ['event_number' => 1, 'sport_classes' => null]);
    $open->scoringGroups()->create(['name' => 'Offen', 'gender' => 'A', 'sort_order' => 1]);
    makeEvent_p5($meet, ['event_number' => 2, 'sport_classes' => null, 'is_scored' => false]);

    $unconfigured = fn () => test()->actingAs($admin)->get(route('meets.index'))
        ->viewData('meets')->firstWhere('id', $meet->id)->unconfigured_events_count;

    expect($unconfigured())->toBe(0);

    makeEvent_p5($meet, ['event_number' => 3, 'sport_classes' => '']);

    expect($unconfigured())->toBe(1);
});

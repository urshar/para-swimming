<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\ScoringGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('lenex-nation-filter');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_lnf(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Österreicherin mit Heimverein, wie im EM-File ohne Lizenz und nur mit Jahrgang. */
function athlete_lnf(Club $club, string $first, string $last): Athlete
{
    return Athlete::create([
        'first_name' => $first, 'last_name' => $last, 'gender' => 'F', 'birth_date' => '2003-01-01',
        'nation_id' => makeNation_p5()->id, 'club_id' => $club->id,
    ]);
}

/**
 * Internationale Ergebnisdatei nach dem Muster der EM Kocaeli: Nationalteams als Vereine ohne Code, Bewerb 1 mit
 * österreichischem Ergebnis, Bewerb 2 nur mit deutschem, Bewerb 3 (Staffel) nur Deutschland. Im Team "Austria"
 * startet zusätzlich eine unbekannte Österreicherin (Neu) und eine Schwimmerin mit eigener Nation GER.
 */
function emXml_lnf(): string
{
    $athlete = fn (int $id, string $first, string $last, string $event, string $extra = '') => '<ATHLETE athleteid="'.$id
        .'" firstname="'.$first.'" lastname="'.$last.'" birthdate="2003-01-01" gender="F"'.$extra.'>'
        .'<HANDICAP free="14" breast="14" medley="14" />'
        .'<RESULTS><RESULT eventid="'.$event.'" resultid="'.(100 + $id).'" swimtime="00:01:18.28" heatid="2001" lane="2" /></RESULTS></ATHLETE>';

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="WPS European Championships" city="Kocaeli" nation="TUR" course="LCM" startdate="2026-09-07">
<SESSIONS><SESSION number="1" date="2026-09-07"><EVENTS>
  <EVENT eventid="1" number="1" round="FIN"><SWIMSTYLE distance="100" relaycount="1" stroke="BACK" name="Women\'s 100m Backstroke S14" />
    <AGEGROUPS><AGEGROUP agegroupid="1" agemax="-1" agemin="-1" name="Open Class"><RANKINGS>
      <RANKING order="1" place="7" resultid="101" /></RANKINGS></AGEGROUP></AGEGROUPS>
    <HEATS><HEAT heatid="2001" number="1" /></HEATS></EVENT>
  <EVENT eventid="2" number="2" round="FIN"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" /></EVENT>
  <EVENT eventid="3" number="3" round="FIN"><SWIMSTYLE distance="50" relaycount="4" stroke="FREE" /></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS>
  <CLUB name="Austria" nation="AUT" type="NATIONALTEAM"><ATHLETES>'
        .$athlete(1, 'Janina', 'Falk', '1')
        .$athlete(2, 'Nora', 'Neu', '1')
        .$athlete(3, 'Gerda', 'Gast', '1', ' nation="GER"').'
  </ATHLETES></CLUB>
  <CLUB name="Germany" nation="GER" type="NATIONALTEAM"><ATHLETES>'
        .$athlete(4, 'Greta', 'Deutsch', '2').'
  </ATHLETES>
    <RELAYS><RELAY number="1" gender="F"><RESULTS><RESULT eventid="3" resultid="900" swimtime="00:02:30.00" /></RESULTS></RELAY></RELAYS>
  </CLUB>
</CLUBS></MEET></MEETS></LENEX>';
}

/** Lädt die Datei hoch und liefert die Antwort der Wettkampf-Auswahl. */
function upload_lnf(User $admin): TestResponse
{
    $upload = test()->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('em.lef', emXml_lnf()),
    ]);

    return test()->actingAs($admin)->get($upload->headers->get('Location'));
}

function sessionKey_lnf(TestResponse $response): string
{
    preg_match('/name="import_session" value="([^"]+)"/', $response->getContent(), $m);

    return $m[1];
}

// ── Auswahl ───────────────────────────────────────────────────────────────────

it('bietet bei mehreren Nationen den Filter mit Vorauswahl Österreich an', function () {
    Storage::fake('local');
    makeStrokeType_p5();

    upload_lnf(admin_lnf())
        ->assertOk()
        ->assertSee('Nur Schwimmer dieser Nation importieren')
        ->assertSee('Die Datei enthält 2 Nationen');
});

// ── Import mit Filter ─────────────────────────────────────────────────────────

it('importiert nur österreichische Schwimmer, deren Bewerbe und ordnet Ergebnisse dem Heimverein zu', function () {
    Storage::fake('local');
    makeStrokeType_p5('BACK');
    makeStrokeType_p5();
    Nation::firstOrCreate(['code' => 'GER'], ['name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);
    $admin = admin_lnf();
    $homeClub = makeClub_p5();
    $falk = athlete_lnf($homeClub, 'Janina', 'Falk');

    $run = test()->actingAs($admin)->post(route('lenex.import.run'), [
        'import_session' => sessionKey_lnf(upload_lnf($admin)), 'meet_id' => '', 'only_nation' => 'AUT', 'meet_nation' => 'AUT',
    ]);

    // Unbekannte Österreicherin "Neu" → Klärungsseite nur mit ihr, kein Verein, keine Deutschen.
    $run->assertRedirectContains('/lenex/import/review');
    $session = session(collect(explode('session=', $run->headers->get('Location')))->last());
    expect($session['unresolved_clubs'])->toBe([])
        ->and(collect($session['unresolved_athletes'])->pluck('last_name')->all())->toBe(['Neu']);

    $meet = Meet::sole();
    $result = Result::sole();
    expect($meet->swimEvents()->pluck('event_number')->all())->toBe([1])
        ->and($result->athlete_id)->toBe($falk->id)
        ->and($result->club_id)->toBe($homeClub->id)
        ->and($result->place)->toBe(7)
        ->and($result->heat)->toBe(1)
        ->and($result->sport_class)->toBe('S14')
        ->and(Club::count())->toBe(1)
        ->and(Athlete::where('last_name', 'Deutsch')->exists())->toBeFalse()
        ->and(Athlete::where('last_name', 'Gast')->exists())->toBeFalse();
});

it('überspringt Ergebnisse neu angelegter Teamschwimmer ohne Heimverein und meldet sie', function () {
    Storage::fake('local');
    makeStrokeType_p5('BACK');
    makeStrokeType_p5();
    $admin = admin_lnf();
    athlete_lnf(makeClub_p5(), 'Janina', 'Falk');
    $run = test()->actingAs($admin)->post(route('lenex.import.run'), [
        'import_session' => sessionKey_lnf(upload_lnf($admin)), 'meet_id' => '', 'only_nation' => 'AUT', 'meet_nation' => 'AUT',
    ]);
    $athleteSession = collect(explode('session=', $run->headers->get('Location')))->last();

    test()->actingAs($admin)->post(route('lenex.import.resolve-athletes'), [
        'import_session' => $athleteSession,
        'athletes' => [['selection' => 'new']],
    ])->assertSessionHas('success', fn (string $m) => str_contains($m, '1 Meldung(en)/Ergebnis(se) übersprungen: Athlet ohne Verein'));

    expect(Athlete::where('last_name', 'Neu')->sole()->club_id)->toBeNull()
        ->and(Result::count())->toBe(1)
        ->and(SwimEvent::count())->toBe(1);
});

it('importiert ohne Filter wie bisher alle Nationen samt Klärung der Vereine', function () {
    Storage::fake('local');
    makeStrokeType_p5('BACK');
    makeStrokeType_p5();
    makeNation_p5();
    $admin = admin_lnf();

    // Ohne Filter zuerst die Abfrage der Bewerbe ohne Klassenangabe ("Open Class"), dann die Vereine.
    $run = test()->actingAs($admin)->post(route('lenex.import.run'), [
        'import_session' => sessionKey_lnf(upload_lnf($admin)), 'meet_id' => '', 'only_nation' => 'ALL', 'meet_nation' => 'AUT',
    ]);
    $events = test()->actingAs($admin)->post(route('lenex.import.resolve-events'), [
        'import_session' => collect(explode('session=', $run->headers->get('Location')))->last(),
    ]);

    $session = session(collect(explode('session=', $events->headers->get('Location')))->last());
    expect(collect($session['unresolved_clubs'])->pluck('name')->all())->toBe(['Austria', 'Germany'])
        ->and(SwimEvent::count())->toBe(3);
});

it('fragt die Nation der Veranstaltung ab, wenn sie in der Datei unbekannt ist', function () {
    Storage::fake('local');
    makeStrokeType_p5('BACK');
    makeStrokeType_p5();
    makeNation_p5();
    $admin = admin_lnf();
    $confirm = upload_lnf($admin);

    $confirm->assertSee('Nation der Veranstaltung');
    test()->actingAs($admin)->post(route('lenex.import.run'), [
        'import_session' => sessionKey_lnf($confirm), 'meet_id' => '', 'only_nation' => 'AUT',
    ])->assertSessionHasErrors('meet_nation');

    expect(Meet::count())->toBe(0);
});

// ── Plätze aus der Datei ──────────────────────────────────────────────────────

it('kennzeichnet die Veranstaltung beim Import mit Nationenfilter und behält den Platz aus der Datei', function () {
    Storage::fake('local');
    makeStrokeType_p5('BACK');
    makeStrokeType_p5();
    $admin = admin_lnf();
    athlete_lnf(makeClub_p5(), 'Janina', 'Falk');
    test()->actingAs($admin)->post(route('lenex.import.run'), [
        'import_session' => sessionKey_lnf(upload_lnf($admin)), 'meet_id' => '', 'only_nation' => 'AUT', 'meet_nation' => 'AUT',
    ]);
    $meet = Meet::sole();
    $result = Result::sole();
    $result->update(['points' => 600]);

    // Neuberechnung (z. B. nach "ÖBSV-Punkte berechnen") darf den EM-Platz nicht überschreiben.
    app(ScoringGroupService::class)->syncPlaces($result->swimEvent);
    $groups = app(ScoringGroupService::class)->rankedGroups($result->swimEvent, Result::with('athlete')->get(), 2026);

    expect($meet->keep_file_places)->toBeTrue()
        ->and($result->fresh()->place)->toBe(7)
        ->and($groups[0]['rows'][0]['place'])->toBe(7)
        ->and($groups[0]['missingPoints'])->toBe(0);
});

it('rechnet ohne Kennzeichen weiterhin selbst', function () {
    Storage::fake('local');
    makeStrokeType_p5('BACK');
    makeStrokeType_p5();
    $admin = admin_lnf();
    athlete_lnf(makeClub_p5(), 'Janina', 'Falk');
    test()->actingAs($admin)->post(route('lenex.import.run'), [
        'import_session' => sessionKey_lnf(upload_lnf($admin)), 'meet_id' => '', 'only_nation' => 'AUT', 'meet_nation' => 'AUT',
    ]);
    Meet::sole()->update(['keep_file_places' => false]);
    $result = Result::sole();
    $result->update(['points' => 600]);

    app(ScoringGroupService::class)->syncPlaces($result->swimEvent->fresh());

    expect($result->fresh()->place)->toBe(1);
});

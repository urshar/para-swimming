<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Result;
use App\Models\User;
use App\Services\ImportSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('lenex-review-swapped-names');

// Klärungsseite des LENEX-Imports bei vertauschtem bzw. verschriebenem Vor-/Nachnamen ("Shaih Habibollah" in der
// Datei, "Habibollah Shahi" in der DB). Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_lrs(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function athlete_lrs(Club $club, string $first, string $last, string $birthDate, ?string $license): Athlete
{
    return Athlete::create([
        'first_name' => $first,
        'last_name' => $last,
        'birth_date' => $birthDate,
        'gender' => 'M',
        'license' => $license,
        'nation_id' => makeNation_p5()->id,
        'club_id' => $club->id,
    ]);
}

/** Ergebnisdatei wie LM Salzburg 2019: Vorname "Shaih", Nachname "Habibollah", fremde Lizenz. */
function resultXml_lrs(Club $club): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Namenstest" city="Salzburg" nation="AUT" course="SCM" startdate="2019-03-02">
<SESSIONS><SESSION number="1" date="2019-03-02"><EVENTS>
  <EVENT eventid="1145" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" gender="M" name="Herren" handicap="1,2,3,4,5,6,7,8,9,10,11,12,13,14" /></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS>
  <CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>
    <ATHLETE athleteid="2072" firstname="Shaih" lastname="Habibollah" birthdate="1974-09-24" gender="M" license="S-0687">
      <RESULTS><RESULT eventid="1145" resultid="2073" swimtime="00:00:36.65" /></RESULTS></ATHLETE>
  </ATHLETES></CLUB>
</CLUBS></MEET></MEETS></LENEX>';
}

/** Lädt die Datei hoch, wählt "neue Veranstaltung" und liefert den Session-Schlüssel der Klärungsseite. */
function reviewSession_lrs(User $admin, string $xml): string
{
    $upload = test()->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('ergebnisse.lef', $xml),
    ]);
    parse_str((string) parse_url($upload->headers->get('Location'), PHP_URL_QUERY), $query);

    $run = test()->actingAs($admin)->post(route('lenex.import.run'), ['import_session' => $query['session'], 'meet_id' => '']);

    return sessionKey_lrs($run);
}

function sessionKey_lrs(TestResponse $response): string
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['session'];
}

// ── Vorschläge über das Geburtsdatum ──────────────────────────────────────────

it('schlägt Athleten mit gleichem Geburtsdatum und vertauschtem, verschriebenem Namen vor', function () {
    $club = makeClub_p5();
    $shahi = athlete_lrs($club, 'Habibollah', 'Shahi', '1974-09-24', 'S-0651');
    athlete_lrs($club, 'Habibollah', 'Anders', '1974-09-25', null);   // anderes Geburtsdatum
    athlete_lrs($club, 'Peter', 'Muster', '1974-09-24', null);        // kein gemeinsamer Namensteil

    $found = (new ImportSuggestionService)->suggestAthletesByBirthDate('Habibollah', 'Shaih', '1974-09-24', 'M', []);

    expect($found->pluck('id')->all())->toBe([$shahi->id]);
});

it('vergleicht Namensteile von Doppelnamen und lässt bereits vorgeschlagene Athleten weg', function () {
    $club = makeClub_p5();
    $weber = athlete_lrs($club, 'Anna', 'Weber - Treiber', '2001-06-17', null);
    $service = new ImportSuggestionService;

    expect($service->suggestAthletesByBirthDate('Treiber', 'Ana', '2001-06-17', 'M', [])->pluck('id')->all())
        ->toBe([$weber->id])
        ->and($service->suggestAthletesByBirthDate('Treiber', 'Ana', '2001-06-17', 'M', [$weber->id]))->toBeEmpty()
        ->and($service->suggestAthletesByBirthDate('Treiber', 'Ana', '2001-06-17', 'F', []))->toBeEmpty()
        ->and($service->suggestAthletesByBirthDate('Treiber', 'Ana', '', 'M', []))->toBeEmpty();
});

// ── Klärungsseite ─────────────────────────────────────────────────────────────

it('bietet den Athleten mit vertauschtem Namen auf der Klärungsseite an, ohne ihn vorzubelegen', function () {
    Storage::fake('local');
    makeStrokeType_p5();
    $admin = admin_lrs();
    $club = makeClub_p5();
    athlete_lrs($club, 'Habibollah', 'Shahi', '1974-09-24', 'S-0651');
    athlete_lrs($club, 'Stefan', 'Sauer', '1980-01-01', null);
    athlete_lrs($club, 'Hans', 'Huber', '1980-01-01', null);

    $session = reviewSession_lrs($admin, resultXml_lrs($club));
    $athlete = session($session)['unresolved_athletes'][0];

    test()->get(route('lenex.import.review', ['session' => $session]))
        ->assertOk()
        ->assertSee('Vorschlag — gleiches Geburtsdatum, Name abweichend oder vertauscht')
        ->assertSee('Shahi, Habibollah (*24.09.1974) · S-0651', false)
        // Dropdown zusätzlich mit dem Anfangsbuchstaben des Vornamens aus der Datei
        ->assertSee('Bestehendem Athleten zuordnen (Nachname H… oder S…)')
        ->assertSee('Sauer, Stefan')
        ->assertSee('Huber, Hans')
        ->assertDontSee('Jahrgang-Treffer vorbelegt');

    expect($athlete['last_name'])->toBe('Habibollah')
        ->and($athlete['first_name'])->toBe('Shaih');
});

it('ordnet das Ergebnis dem gewählten Athleten zu, ohne Name oder Lizenz zu ändern', function () {
    Storage::fake('local');
    makeStrokeType_p5();
    $admin = admin_lrs();
    $club = makeClub_p5();
    $shahi = athlete_lrs($club, 'Habibollah', 'Shahi', '1974-09-24', 'S-0651');

    $session = reviewSession_lrs($admin, resultXml_lrs($club));
    test()->actingAs($admin)->post(route('lenex.import.resolve-athletes'), [
        'import_session' => $session,
        'athletes' => [['selection' => (string) $shahi->id]],
    ])->assertRedirect(route('meets.index'));

    $fresh = $shahi->fresh();
    expect(Result::pluck('athlete_id')->all())->toBe([$shahi->id])
        ->and(Athlete::count())->toBe(1)
        ->and($fresh->first_name)->toBe('Habibollah')
        ->and($fresh->last_name)->toBe('Shahi')
        ->and($fresh->license)->toBe('S-0651');
});

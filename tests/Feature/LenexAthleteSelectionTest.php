<?php

use App\Http\Controllers\LenexImportController;
use App\Models\Athlete;
use App\Models\AthleteClassification;
use App\Models\AthleteSportClass;
use App\Models\Club;
use App\Models\ForeignStart;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\LenexAthleteSelectionService;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('lenex-athlete-selection');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function athlete_las(Club $club, string $first, string $last, array $extra): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => $first, 'last_name' => $last, 'gender' => 'M', 'birth_date' => '2005-03-04',
        'nation_id' => makeNation_p5()->id, 'club_id' => $club->id,
    ], $extra));
}

/**
 * Ergebnisdatei nach dem Muster eines OSV-Meetings: Bewerb 1 und 2 mit Jahrgangsgruppen (keine Klassen), Bewerb 3 mit
 * Para-Gruppe "S1-S10", Bewerb 4 eine Staffel. Im Verein "SV Linz" starten Bekannte (Lizenz, SDMS-ID, Name), ein
 * Unbekannter mit HANDICAP, ein Unbekannter nur im Para-Bewerb, ein Unbekannter ohne Para-Hinweis und ein Bekannter,
 * der nur in der Staffel schwimmt.
 */
function osvXml_las(): string
{
    $athlete = fn (int $id, string $first, string $last, string $attrs, string $events, string $inner = '') => '<ATHLETE athleteid="'
        .$id.'" firstname="'.$first.'" lastname="'.$last.'" birthdate="2005-03-04" gender="M"'.$attrs.'>'.$inner
        .'<RESULTS>'.implode('', array_map(
            fn (string $event): string => '<RESULT eventid="'.$event.'" resultid="'.$id.$event.'" swimtime="00:01:10.00" />',
            explode(',', $events),
        )).'</RESULTS></ATHLETE>';

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Linzer Herbstmeeting" city="Linz" nation="AUT" course="SCM" startdate="2016-10-15">
<SESSIONS><SESSION number="1" date="2016-10-15"><EVENTS>
  <EVENT eventid="1" number="1"><SWIMSTYLE distance="100" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" agemin="6" agemax="8" name="Jg. 2008-2010"><RANKINGS>
      <RANKING order="1" place="12" resultid="31" /><RANKING order="2" place="37" resultid="11" />
    </RANKINGS></AGEGROUP></AGEGROUPS></EVENT>
  <EVENT eventid="2" number="2"><SWIMSTYLE distance="50" relaycount="1" stroke="BREAST" />
    <AGEGROUPS><AGEGROUP agegroupid="2" agemin="-1" agemax="-1" name="Offen" /></AGEGROUPS></EVENT>
  <EVENT eventid="3" number="3"><SWIMSTYLE distance="50" relaycount="1" stroke="BACK" />
    <AGEGROUPS><AGEGROUP agegroupid="3" agemin="-1" agemax="-1" name="S1-S10" /></AGEGROUPS></EVENT>
  <EVENT eventid="4" number="4"><SWIMSTYLE distance="50" relaycount="4" stroke="FREE" /></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS>
  <CLUB name="SV Linz" code="SVL" nation="AUT"><ATHLETES>'
        .$athlete(1, 'Lisa', 'Lizenz', ' license="O-12 34"', '1,2')
        .$athlete(2, 'Simon', 'Sdms', ' license_ipc="98765"', '2')
        .$athlete(3, 'Nina', 'Name', '', '1', '<HANDICAP free="9" breast="8" />')
        .$athlete(4, 'Hans', 'Handicap', '', '1', '<HANDICAP free="5" />')
        .$athlete(5, 'Paul', 'Parabewerb', '', '3')
        .$athlete(6, 'Otto', 'Offen', '', '1,2')
        .$athlete(7, 'Sepp', 'Staffel', ' license="O-777"', '4').'
  </ATHLETES></CLUB>
  <CLUB name="SC Wels" nation="AUT"><ATHLETES>'
        .$athlete(8, 'Gustav', 'Gast', ' nation="GER"', '1').'
  </ATHLETES></CLUB>
</CLUBS></MEET></MEETS></LENEX>';
}

/**
 * Schreibt die Datei in eine Temp-Datei und ruft $use damit auf. Der Parser erkennt ZIP bzw. XML am Inhalt, die
 * Dateiendung spielt keine Rolle.
 *
 * @template T
 *
 * @param  Closure(string): T  $use
 * @return T
 */
function withFile_las(Closure $use): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'las');
    file_put_contents($path, osvXml_las());

    try {
        return $use($path);
    } finally {
        unlink($path);
    }
}

/** @throws Exception */
function preview_las(): array
{
    return withFile_las(fn (string $path): array => app(LenexAthleteSelectionService::class)->preview($path));
}

/**
 * Importiert die Datei mit Athletenauswahl.
 *
 * @param  array<string, int|null>  $selection
 * @param  int|null  $meetId  bestehende Veranstaltung (wie "Wettkampf zuordnen"), null = aus der Datei anlegen
 * @return LenexResolverService der Resolver nach dem Lauf (für die vorgemerkten Klärungsfälle)
 *
 * @throws Exception
 */
function import_las(array $selection, bool $filePlaces, ?int $meetId): LenexResolverService
{
    foreach (['FREE', 'BREAST', 'BACK'] as $stroke) {
        makeStrokeType_p5($stroke);
    }
    $resolver = new LenexResolverService;
    withFile_las(fn (string $path): array => app(LenexParserService::class)->import(
        $path, $resolver, $meetId, ['only_athletes' => $selection, 'file_places' => $filePlaces]
    ));

    return $resolver;
}

/** @return array{0: Athlete, 1: Athlete, 2: Athlete} */
function knownAthletes_las(): array
{
    $club = makeClub_p5();

    return [
        athlete_las($club, 'Lisa', 'Lizenz', ['gender' => 'F', 'license' => 'O-1234']),
        athlete_las($club, 'Simon', 'Sdms', ['license_ipc' => '98765']),
        athlete_las($club, 'Nina', 'Name', []),
    ];
}

// ── Treffer ───────────────────────────────────────────────────────────────────

it('findet bekannte Athleten über Lizenz, SDMS-ID und Name mit Geburtsdatum', /** @throws Exception */ function () {
    [$license, $sdms, $name] = knownAthletes_las();

    $matches = collect(preview_las()['matches'])->keyBy('lenex_id');

    expect($matches->pluck('lenex_id')->sort()->values()->all())->toBe(['1', '2', '3'])
        ->and($matches['1']['athlete_id'])->toBe($license->id)
        ->and($matches['1']['matched_by'])->toBe(LenexResolverService::MATCH_LICENSE)
        ->and($matches['1']['events'])->toBe(['100 m FREE', '50 m BREAST'])
        ->and($matches['2']['athlete_id'])->toBe($sdms->id)
        ->and($matches['2']['matched_by'])->toBe(LenexResolverService::MATCH_LICENSE_IPC)
        ->and($matches['3']['athlete_id'])->toBe($name->id)
        ->and($matches['3']['matched_by'])->toBe(LenexResolverService::MATCH_NAME)
        ->and($matches['3']['athlete_name'])->toBe('Name, Nina')
        ->and($matches['3']['birth_year'])->toBe('2005')
        ->and($matches['3']['file_club'])->toBe('SV Linz')
        ->and($matches['3']['file_nation'])->toBe('AUT');
});

it('findet über Name und Geburtsdatum nur Athleten derselben Nation', /** @throws Exception */ function () {
    makeNation_p5();
    $ger = Nation::create(['code' => 'GER', 'name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);
    // Gleicher Name und Geburtstag, aber Österreicher — der Schwimmer der Datei ist laut nation-Attribut Deutscher.
    athlete_las(makeClub_p5(), 'Gustav', 'Gast', []);

    $preview = preview_las();

    expect(collect($preview['matches'])->pluck('lenex_id')->all())->not->toContain('8');

    Athlete::where('last_name', 'Gast')->update(['nation_id' => $ger->id]);
    expect(collect(preview_las()['matches'])->firstWhere('lenex_id', '8')['file_nation'])->toBe('GER');
});

it('lässt Schwimmer weg, die nur in einer Staffel starten', /** @throws Exception */ function () {
    athlete_las(makeClub_p5(), 'Sepp', 'Staffel', ['license' => 'O-777']);

    $preview = preview_las();

    expect(collect($preview['matches'])->pluck('lenex_id')->all())->not->toContain('7')
        ->and(collect($preview['candidates'])->pluck('lenex_id')->all())->not->toContain('7');
});

// ── Kandidaten ────────────────────────────────────────────────────────────────

it('bietet nur nicht gefundene Schwimmer mit Para-Hinweis als Kandidaten an', /** @throws Exception */ function () {
    knownAthletes_las();

    $candidates = collect(preview_las()['candidates'])->keyBy('lenex_id');

    // 4: HANDICAP-Element, 5: Start im Para-Bewerb; 6 (offenes Feld) und 8 (Gast ohne Para-Hinweis) nicht.
    expect($candidates->pluck('lenex_id')->sort()->values()->all())->toBe(['4', '5'])
        ->and($candidates['4']['file_name'])->toBe('Handicap, Hans')
        ->and($candidates['4']['birth_date'])->toBe('2005-03-04')
        ->and($candidates['4']['gender'])->toBe('M')
        ->and($candidates['5']['events'])->toBe(['50 m BACK']);
});

// ── Keine Nebenwirkungen ──────────────────────────────────────────────────────

it('schreibt beim Abgleich nichts in die Datenbank', /** @throws Exception */ function () {
    [, , $name] = knownAthletes_las();
    $counts = fn (): array => [
        Athlete::count(), Club::count(), AthleteSportClass::count(), AthleteClassification::count(),
    ];
    $before = $counts();

    preview_las();

    // Nina Name steht mit HANDICAP in der Datei, hat aber keine Klassen: Der Import würde sie ergänzen, die Vorschau nicht.
    expect($counts())->toBe($before)
        ->and($name->sportClasses()->count())->toBe(0);
});

// ── Import mit Athletenauswahl ────────────────────────────────────────────────

it('importiert nur Einzelergebnisse der ausgewählten Athleten in deren Bewerben', /** @throws Exception */ function () {
    [$lisa, , $nina] = knownAthletes_las();
    $clubs = Club::count();
    $athletes = Athlete::count();

    $resolver = import_las(['1' => $lisa->id, '3' => $nina->id], false, null);

    $meet = Meet::where('name', 'Linzer Herbstmeeting')->firstOrFail();
    // Lisa: 100 Freistil + 50 Brust, Nina: 100 Freistil. Simon (Treffer, aber nicht ausgewählt) und alle anderen nicht.
    expect(Result::where('meet_id', $meet->id)->count())->toBe(3)
        ->and(Result::pluck('athlete_id')->unique()->sort()->values()->all())->toBe([$lisa->id, $nina->id])
        // Nur Bewerbe mit ausgewählten Startern: kein Para-Bewerb 3, keine Staffel 4.
        ->and(SwimEvent::where('meet_id', $meet->id)->orderBy('event_number')->pluck('event_number')->all())->toBe([1, 2])
        ->and(RelayResult::count())->toBe(0)
        ->and(ForeignStart::count())->toBe(0)
        ->and(ScoringGroup::count())->toBe(0)
        ->and(Club::count())->toBe($clubs)
        ->and(Athlete::count())->toBe($athletes)
        // Verein "SV Linz" ist unbekannt: kein Klärungsfall, die Ergebnisse gehen an den Heimverein.
        ->and($resolver->getUnresolvedClubs())->toBe([])
        ->and($resolver->getUnresolvedAthletes())->toBe([])
        ->and(Result::where('athlete_id', $lisa->id)->pluck('club_id')->unique()->all())->toBe([$lisa->club_id]);
});

it('ordnet Ergebnisse einem bekannten Verein der Datei zu', /** @throws Exception */ function () {
    [, , $nina] = knownAthletes_las();
    $linz = Club::create(['name' => 'SV Linz', 'short_name' => 'SVL', 'code' => 'SVL', 'nation_id' => makeNation_p5()->id]);

    import_las(['3' => $nina->id], false, null);

    expect(Result::where('athlete_id', $nina->id)->value('club_id'))->toBe($linz->id);
});

it('leitet eine fehlende Sportklasse aus dem Athleten zur Lage des Bewerbs ab', /** @throws Exception */ function () {
    [$lisa, , $nina] = knownAthletes_las();
    AthleteSportClass::create(['athlete_id' => $lisa->id, 'category' => 'SB', 'class_number' => '4', 'sport_class' => 'SB4']);

    import_las(['1' => $lisa->id, '3' => $nina->id], false, null);

    $breast = SwimEvent::where('event_number', 2)->value('id');
    $free = SwimEvent::where('event_number', 1)->value('id');
    // Lisa hat in der Datei kein HANDICAP → aus den Stammdaten; Nina hat HANDICAP free="9" → wie bisher aus der Datei.
    expect(Result::where('athlete_id', $lisa->id)->where('swim_event_id', $breast)->value('sport_class'))->toBe('SB4')
        ->and(Result::where('athlete_id', $nina->id)->where('swim_event_id', $free)->value('sport_class'))->toBe('S9');
});

it('übernimmt auf Wunsch die Plätze aus der Datei', /** @throws Exception */ function () {
    [$lisa, , $nina] = knownAthletes_las();

    import_las(['1' => $lisa->id, '3' => $nina->id], true, null);

    $free = SwimEvent::where('event_number', 1)->value('id');
    expect(Meet::where('name', 'Linzer Herbstmeeting')->value('keep_file_places'))->toBeTrue()
        ->and(Result::where('athlete_id', $lisa->id)->where('swim_event_id', $free)->value('place'))->toBe(37)
        ->and(Result::where('athlete_id', $nina->id)->where('swim_event_id', $free)->value('place'))->toBe(12);
});

it('berechnet die Plätze sonst unter den importierten Schwimmern', /** @throws Exception */ function () {
    [, , $nina] = knownAthletes_las();

    import_las(['3' => $nina->id], false, null);

    // In der Datei Platz 12 im offenen Feld, unter den importierten Schwimmern allein in ihrer Wertung → Platz 1.
    expect(Meet::where('name', 'Linzer Herbstmeeting')->value('keep_file_places'))->toBeFalse()
        ->and(Result::where('athlete_id', $nina->id)->value('place'))->toBe(1);
});

it('merkt ausgewählte Kandidaten zur Klärung vor, nicht ausgewählte Unbekannte nicht', /** @throws Exception */ function () {
    knownAthletes_las();

    $resolver = import_las(['4' => null], false, null);

    expect(collect($resolver->getUnresolvedAthletes())->pluck('lenex_id')->all())->toBe(['4'])
        ->and(Result::count())->toBe(0);
});

it('verdoppelt bei einem erneuten Import mit größerer Auswahl nichts', /** @throws Exception */ function () {
    [$lisa, , $nina] = knownAthletes_las();

    import_las(['1' => $lisa->id], false, null);
    // Zweiter Lauf auf die bestehende Veranstaltung, wie im Ablauf über "Wettkampf zuordnen".
    import_las(['1' => $lisa->id, '3' => $nina->id], false, Meet::value('id'));

    expect(Result::count())->toBe(3)
        ->and(Result::where('athlete_id', $lisa->id)->count())->toBe(2);
});

it('lässt Nationenfilter und Athletenauswahl nicht gemeinsam zu', /** @throws Exception */ function () {
    withFile_las(fn (string $path): array => app(LenexParserService::class)->import(
        $path, new LenexResolverService, null, ['only_nation' => 'AUT', 'only_athletes' => []]
    ));
})->throws(InvalidArgumentException::class);

// ── Ablauf über die Oberfläche ────────────────────────────────────────────────

function admin_las(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Lädt die Datei hoch und liefert die Antwort von "Wettkampf zuordnen". */
function upload_las(User $admin): TestResponse
{
    foreach (['FREE', 'BREAST', 'BACK'] as $stroke) {
        makeStrokeType_p5($stroke);
    }
    $upload = test()->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('osv.lef', osvXml_las()),
    ]);

    return test()->actingAs($admin)->get($upload->headers->get('Location'));
}

function sessionKey_las(TestResponse $response): string
{
    preg_match('/name="import_session" value="([^"]+)"/', $response->getContent(), $m);

    return $m[1];
}

/** "Wettkampf zuordnen" mit Importumfang "Nur ausgewählte Athleten" → Auswahlschritt. */
function selectionPage_las(User $admin): TestResponse
{
    $run = test()->actingAs($admin)->post(route('lenex.import.run'), [
        'import_session' => sessionKey_las(upload_las($admin)), 'meet_id' => '',
        'only_nation' => LenexImportController::SCOPE_SELECTED,
    ]);
    $run->assertRedirectContains('/lenex/import/select-athletes');

    return test()->actingAs($admin)->get($run->headers->get('Location'));
}

it('bietet bei Ergebnisdateien den Importumfang "Nur ausgewählte Athleten" an', function () {
    Storage::fake('local');

    upload_las(admin_las())
        ->assertOk()
        ->assertSee('Importumfang')
        ->assertSee('Nur ausgewählte Athleten');
});

it('zeigt im Auswahlschritt Treffer und Kandidaten mit Platzvorbelegung für Österreich', function () {
    Storage::fake('local');
    knownAthletes_las();

    selectionPage_las(admin_las())
        ->assertOk()
        ->assertSee('In der Datenbank gefunden (3)')
        ->assertSee('Name, Nina')
        ->assertSee('Name + Geburtsdatum')
        ->assertSee('Kandidaten ohne Treffer (2)')
        ->assertSee('Handicap, Hans')
        // Otto (offenes Feld) und Gustav (Gast) sind keine Kandidaten, stehen aber unter "Weitere Schwimmer"
        // (docs/specs/lenex-athlete-selection-other-swimmers.md).
        ->assertSee('Weitere Schwimmer (2)')
        ->assertViewHas('defaultPlaces', 'compute');
});

it('importiert nach der Auswahl nur die angehakten Athleten', function () {
    Storage::fake('local');
    [$lisa, , $nina] = knownAthletes_las();
    $admin = admin_las();
    $page = selectionPage_las($admin);

    test()->actingAs($admin)->post(route('lenex.import.apply-athlete-selection'), [
        'import_session' => sessionKey_las($page), 'athletes' => ['3'], 'places' => 'compute',
    ])->assertRedirect(route('meets.index'));

    expect(Result::pluck('athlete_id')->unique()->values()->all())->toBe([$nina->id])
        ->and(Result::where('athlete_id', $lisa->id)->exists())->toBeFalse()
        ->and(Meet::sole()->keep_file_places)->toBeFalse();
});

it('übernimmt nur angebotene Schwimmer und verlangt mindestens einen', function () {
    Storage::fake('local');
    knownAthletes_las();
    $admin = admin_las();
    $session = sessionKey_las(selectionPage_las($admin));

    // Otto (6) wurde nicht angeboten: zählt nicht, also ist nichts ausgewählt.
    test()->actingAs($admin)->post(route('lenex.import.apply-athlete-selection'), [
        'import_session' => $session, 'athletes' => ['6'], 'candidates' => ['6'], 'places' => 'file',
    ])
        ->assertRedirect(route('lenex.import.select-athletes', ['session' => $session]))
        ->assertSessionHas('error', 'Bitte mindestens einen Athleten auswählen.');

    expect(Result::count())->toBe(0)
        ->and(Meet::count())->toBe(0);
});

it('führt angehakte Kandidaten über die Klärungsseite und importiert danach nur sie dazu', function () {
    Storage::fake('local');
    [, , $nina] = knownAthletes_las();
    // Bekannter Verein: Der neu angelegte Kandidat bekommt ihn als Heimverein (sonst siehe nächster Test).
    $linz = Club::create(['name' => 'SV Linz', 'short_name' => 'SVL', 'code' => 'SVL', 'nation_id' => makeNation_p5()->id]);
    $admin = admin_las();
    $page = selectionPage_las($admin);

    $apply = test()->actingAs($admin)->post(route('lenex.import.apply-athlete-selection'), [
        'import_session' => sessionKey_las($page), 'athletes' => ['3'], 'candidates' => ['4'], 'places' => 'file',
    ]);
    $apply->assertRedirectContains('/lenex/import/review');
    $review = test()->actingAs($admin)->get($apply->headers->get('Location'))->assertOk()->assertSee('Handicap');

    test()->actingAs($admin)->post(route('lenex.import.resolve-athletes'), [
        'import_session' => sessionKey_las($review), 'athletes' => [['selection' => 'new']],
    ])->assertRedirect(route('meets.index'));

    $hans = Athlete::where('last_name', 'Handicap')->sole();
    expect(Result::pluck('athlete_id')->unique()->sort()->values()->all())->toBe(collect([$nina->id, $hans->id])->sort()->values()->all())
        ->and($hans->club_id)->toBe($linz->id)
        ->and(Athlete::where('last_name', 'Offen')->exists())->toBeFalse()
        ->and(RelayResult::count())->toBe(0)
        ->and(Meet::sole()->keep_file_places)->toBeTrue();
});

it('meldet Ergebnisse eines neu angelegten Kandidaten ohne bekannten Verein als übersprungen', function () {
    Storage::fake('local');
    knownAthletes_las();
    $admin = admin_las();

    $apply = test()->actingAs($admin)->post(route('lenex.import.apply-athlete-selection'), [
        'import_session' => sessionKey_las(selectionPage_las($admin)), 'candidates' => ['4'], 'places' => 'compute',
    ]);
    $review = test()->actingAs($admin)->get($apply->headers->get('Location'));

    test()->actingAs($admin)->post(route('lenex.import.resolve-athletes'), [
        'import_session' => sessionKey_las($review), 'athletes' => [['selection' => 'new']],
    ])->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 Meldung(en)/Ergebnis(se) übersprungen: Athlet ohne Verein'));

    expect(Athlete::where('last_name', 'Handicap')->value('club_id'))->toBeNull()
        ->and(Result::count())->toBe(0);
});

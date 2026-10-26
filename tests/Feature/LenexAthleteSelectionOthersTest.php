<?php

use App\Http\Controllers\LenexImportController;
use App\Models\Athlete;
use App\Models\AthleteSportClass;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\LenexAthleteSelectionService;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('lenex-athlete-selection-others');

// Athletenauswahl: Para-Schwimmer ohne Sportklasse bei Fremdvereinen (docs/specs/lenex-athlete-selection-other-swimmers.md).
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function athlete_lso(string $first, string $last, string $gender, string $birthDate, array $extra): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'birth_date' => $birthDate,
        'nation_id' => makeNation_p5()->id, 'club_id' => makeClub_p5()->id,
    ], $extra));
}

/**
 * Ergebnisdatei nach dem Muster eines OSV-Meetings (Lagen Cup, LSV Wien): nur Jahrgang als Geburtsdatum, OSV-Lizenzen,
 * keine Klassen, Verein "SC Diana Wien" ohne Bezug zum ÖBSV.
 */
function osvXml_lso(): string
{
    $athlete = fn (int $id, string $first, string $last, string $gender, string $birthDate, string $attrs) => '<ATHLETE athleteid="'
        .$id.'" firstname="'.$first.'" lastname="'.$last.'" birthdate="'.$birthDate.'" gender="'.$gender.'"'.$attrs.'>'
        .'<RESULTS><RESULT eventid="1" resultid="'.$id.'1" swimtime="00:01:10.00" />'
        .'<RESULT eventid="2" resultid="'.$id.'2" swimtime="00:00:45.00" /></RESULTS></ATHLETE>';

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Lagen Cup" city="Wien" nation="AUT" course="SCM" startdate="2019-01-12">
<SESSIONS><SESSION number="1" date="2019-01-12"><EVENTS>
  <EVENT eventid="1" number="1"><SWIMSTYLE distance="100" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" agemin="-1" agemax="-1" name="Offen" /></AGEGROUPS></EVENT>
  <EVENT eventid="2" number="2"><SWIMSTYLE distance="50" relaycount="1" stroke="BREAST" />
    <AGEGROUPS><AGEGROUP agegroupid="2" agemin="-1" agemax="-1" name="Offen" /></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS>
  <CLUB name="SC Diana Wien" code="DIA" nation="AUT"><ATHLETES>'
        .$athlete(1, 'Janina', 'Falk', 'F', '2003-01-01', ' license="43909"')
        .$athlete(2, 'Max', 'Mehrfach', 'M', '2004-01-01', '')
        .$athlete(3, 'Anna', 'Anders', 'F', '2004-01-01', '')
        .$athlete(4, 'Gerd', 'Geschlecht', 'M', '2004-01-01', '')
        .$athlete(5, 'Nora', 'Nation', 'F', '2004-01-01', ' nation="GER"')
        .$athlete(6, 'Lena', 'Lizenz', 'F', '2001-01-01', ' license="W-1"').'
  </ATHLETES></CLUB>
</CLUBS></MEET></MEETS></LENEX>';
}

/**
 * Bekannte Athleten der Datenbank: Falk (Jahrgangs-Treffer), zwei Mehrfachs desselben Jahrgangs (mehrdeutig),
 * Anders mit anderem Jahrgang, Geschlecht als Frau, Nation als Österreicherin, Lizenz mit anderem Jahrgang.
 *
 * @return array{falk: Athlete, lizenz: Athlete}
 */
function knownAthletes_lso(): array
{
    athlete_lso('Max', 'Mehrfach', 'M', '2004-03-01', []);
    athlete_lso('Max', 'Mehrfach', 'M', '2004-09-01', []);
    athlete_lso('Anna', 'Anders', 'F', '2005-06-01', []);
    athlete_lso('Gerd', 'Geschlecht', 'F', '2004-06-01', []);
    athlete_lso('Nora', 'Nation', 'F', '2004-06-01', []);

    return [
        'falk' => athlete_lso('Janina', 'Falk', 'F', '2003-02-05', ['license' => 'W-1886']),
        'lizenz' => athlete_lso('Lena', 'Lizenz', 'F', '1999-07-07', ['license' => 'W-1']),
    ];
}

/**
 * @template T
 *
 * @param  Closure(string): T  $use
 * @return T
 */
function withFile_lso(Closure $use): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'lso');
    file_put_contents($path, osvXml_lso());

    try {
        return $use($path);
    } finally {
        unlink($path);
    }
}

/** @throws Exception */
function preview_lso(): array
{
    return withFile_lso(fn (string $path): array => app(LenexAthleteSelectionService::class)->preview($path));
}

/** ATHLETE-Element der Datei zur LENEX athleteid. */
function athleteXml_lso(string $lenexId): SimpleXMLElement
{
    return simplexml_load_string(osvXml_lso())->xpath('//ATHLETE[@athleteid="'.$lenexId.'"]')[0];
}

// ── Vorschau ──────────────────────────────────────────────────────────────────

it('findet einen bekannten Athleten über Name und Jahrgang, nicht vorausgewählt', /** @throws Exception */ function () {
    ['falk' => $falk] = knownAthletes_lso();

    $match = collect(preview_lso()['matches'])->firstWhere('lenex_id', '1');

    expect($match['athlete_id'])->toBe($falk->id)
        ->and($match['matched_by'])->toBe(LenexResolverService::MATCH_NAME_YEAR)
        ->and($match['preselected'])->toBeFalse()
        ->and($match['birth_year'])->toBe('2003')
        ->and($match['file_club'])->toBe('SC Diana Wien')
        ->and($match['events'])->toBe(['100 m FREE', '50 m BREAST']);
});

it('findet keinen Jahrgangs-Treffer bei mehreren Athleten, anderem Jahr, Geschlecht oder anderer Nation', /** @throws Exception */ function () {
    knownAthletes_lso();
    Nation::create(['code' => 'GER', 'name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);

    $preview = preview_lso();

    // 2: zwei Athleten im Jahrgang 2004, 3: Jahrgang 2005, 4: in der Datenbank weiblich, 5: laut Datei Deutsche.
    expect(collect($preview['matches'])->pluck('lenex_id')->sort()->values()->all())->toBe(['1', '6'])
        ->and($preview['candidates'])->toBe([]);
});

it('gibt Lizenz und den übrigen Regeln Vorrang vor dem Jahrgang', /** @throws Exception */ function () {
    ['lizenz' => $lizenz] = knownAthletes_lso();
    // Gleicher Name im Jahrgang der Datei — die Lizenz zeigt aber auf Lena mit anderem Jahrgang.
    athlete_lso('Lena', 'Lizenz', 'F', '2001-05-05', []);

    $match = collect(preview_lso()['matches'])->firstWhere('lenex_id', '6');

    expect($match['athlete_id'])->toBe($lizenz->id)
        ->and($match['matched_by'])->toBe(LenexResolverService::MATCH_LICENSE)
        ->and($match['preselected'])->toBeTrue();
});

it('findet Jahrgangs-Treffer beim normalen Import nicht', function () {
    ['falk' => $falk] = knownAthletes_lso();
    $resolver = new LenexResolverService;

    $athlete = $resolver->resolveAthlete(athleteXml_lso('1'), null, $falk->nation_id);

    expect($athlete)->toBeNull()
        ->and($resolver->findAthlete(athleteXml_lso('1'), $falk->nation_id))->toBeNull()
        ->and(collect($resolver->getUnresolvedAthletes())->pluck('lenex_id')->all())->toBe(['1']);
});

// ── Import ────────────────────────────────────────────────────────────────────

it('importiert einen ausgewählten Jahrgangs-Treffer mit Klasse aus den Stammdaten und Heimverein', /** @throws Exception */ function () {
    ['falk' => $falk] = knownAthletes_lso();
    foreach (['S' => 'S14', 'SB' => 'SB14'] as $category => $class) {
        AthleteSportClass::create(['athlete_id' => $falk->id, 'category' => $category, 'class_number' => '14', 'sport_class' => $class]);
    }
    foreach (['FREE', 'BREAST'] as $stroke) {
        makeStrokeType_p5($stroke);
    }
    $clubs = Club::count();

    withFile_lso(fn (string $path): array => app(LenexParserService::class)->import(
        $path, new LenexResolverService, null, ['only_athletes' => ['1' => $falk->id], 'file_places' => false]
    ));

    $free = SwimEvent::where('event_number', 1)->value('id');
    $breast = SwimEvent::where('event_number', 2)->value('id');
    $falk->refresh();
    expect(Result::where('athlete_id', $falk->id)->count())->toBe(2)
        ->and(Result::where('swim_event_id', $free)->value('sport_class'))->toBe('S14')
        ->and(Result::where('swim_event_id', $breast)->value('sport_class'))->toBe('SB14')
        // "SC Diana Wien" ist unbekannt → Heimverein, kein neuer Verein
        ->and(Result::pluck('club_id')->unique()->all())->toBe([$falk->club_id])
        ->and(Club::count())->toBe($clubs)
        // Stammdaten bleiben unverändert (keine OSV-Lizenz, kein Jahrgangsdatum)
        ->and($falk->license)->toBe('W-1886')
        ->and($falk->birth_date->format('Y-m-d'))->toBe('2003-02-05');
});

// ── Oberfläche ────────────────────────────────────────────────────────────────

function selectionPage_lso(User $admin): TestResponse
{
    foreach (['FREE', 'BREAST'] as $stroke) {
        makeStrokeType_p5($stroke);
    }
    $upload = test()->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('osv.lef', osvXml_lso()),
    ]);
    $confirm = test()->actingAs($admin)->get($upload->headers->get('Location'));
    preg_match('/name="import_session" value="([^"]+)"/', $confirm->getContent(), $m);

    $run = test()->actingAs($admin)->post(route('lenex.import.run'), [
        'import_session' => $m[1], 'meet_id' => '', 'only_nation' => LenexImportController::SCOPE_SELECTED,
    ]);

    return test()->actingAs($admin)->get($run->headers->get('Location'));
}

it('zeigt Jahrgangs-Treffer im Auswahlschritt nicht angehakt und importiert sie, wenn angehakt', function () {
    Storage::fake('local');
    ['falk' => $falk, 'lizenz' => $lizenz] = knownAthletes_lso();
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);

    $page = selectionPage_lso($admin)
        ->assertOk()
        ->assertSee('In der Datenbank gefunden (2)')
        ->assertSee('Name + Jahrgang');
    $html = $page->getContent();
    preg_match('/name="import_session" value="([^"]+)"/', $html, $m);

    expect($html)->toMatch('/name="athletes\[\]" value="6"\s+checked/')
        ->and($html)->not->toMatch('/name="athletes\[\]" value="1"\s+checked/');

    test()->actingAs($admin)->post(route('lenex.import.apply-athlete-selection'), [
        'import_session' => $m[1], 'athletes' => ['1'], 'places' => 'compute',
    ])->assertRedirect(route('meets.index'));

    expect(Result::pluck('athlete_id')->unique()->values()->all())->toBe([$falk->id])
        ->and(Result::where('athlete_id', $lizenz->id)->exists())->toBeFalse()
        ->and(Meet::count())->toBe(1);
});

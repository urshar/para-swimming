<?php

use App\Models\Athlete;
use App\Models\AthleteClassification;
use App\Models\AthleteSportClass;
use App\Models\Club;
use App\Models\Nation;
use App\Services\LenexAthleteSelectionService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    <AGEGROUPS><AGEGROUP agegroupid="1" agemin="6" agemax="8" name="Jg. 2008-2010" /></AGEGROUPS></EVENT>
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

/** @throws Exception */
function preview_las(): array
{
    // Der Parser erkennt ZIP bzw. XML am Inhalt, die Dateiendung spielt keine Rolle.
    $path = tempnam(sys_get_temp_dir(), 'las');
    file_put_contents($path, osvXml_las());

    try {
        return app(LenexAthleteSelectionService::class)->preview($path);
    } finally {
        unlink($path);
    }
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

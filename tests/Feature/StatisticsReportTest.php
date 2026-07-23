<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Support\ReportConfiguration;

uses()->group('statistik-p13');

// ── Helpers ──────────────────────────────────────────────────────────────────

function rep13_nation(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code === 'AUT' ? 'Österreich' : $code, 'name_en' => $code, 'is_active' => true]
    );
}

function rep13_strokeType(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        [
            'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
            'category' => 'standard', 'is_active' => true,
        ]
    );
}

function rep13_meet(string $name, string $startDate): Meet
{
    return Meet::create([
        'name' => $name, 'nation_id' => rep13_nation()->id,
        'course' => 'LCM', 'start_date' => $startDate,
    ]);
}

function rep13_athlete(string $lastName, string $nationCode = 'AUT'): Athlete
{
    return Athlete::create([
        'first_name' => 'Test', 'last_name' => $lastName, 'gender' => 'F',
        'birth_date' => '2000-01-01', 'nation_id' => rep13_nation($nationCode)->id, 'is_active' => true,
    ]);
}

function rep13_start(Meet $meet, Athlete $athlete, Club $club): Result
{
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => rep13_strokeType()->id,
        'distance' => 100, 'gender' => 'A', 'relay_count' => 1,
    ]);

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'swim_time' => 6000,
    ]);
}

/** Alle Abschnitte aktiviert, wie es das Formular des Dashboards sendet. */
function rep13_allSections(): array
{
    return array_fill_keys(ReportConfiguration::SECTION_KEYS, '1');
}

// ── Zugriff und Validierung ──────────────────────────────────────────────────

it('leitet nicht angemeldete Besucher auf die Login-Seite', function () {
    $this->get(route('statistics.report', ['year' => 2024]))->assertRedirect(route('login'));
});

it('ist für angemeldete Nutzer zugänglich', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', ['year' => 2024]))
        ->assertOk()
        ->assertSee('Jahresbericht 2024');
});

it('lehnt einen Aufruf ohne Jahr ab', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report'))
        ->assertSessionHasErrors('year');
});

// ── Abschnittssteuerung ──────────────────────────────────────────────────────

it('zeigt alle angeforderten Abschnitte an', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', ['year' => 2024, 'sections' => rep13_allSections()]))
        ->assertOk()
        ->assertSee('Allgemeiner Überblick')
        ->assertSee('Teilnehmer und Starts pro Veranstaltung')
        ->assertSee('Vereinsstatistik')
        ->assertSee('Sportlerstatistik')
        ->assertSee('Ausländische Teilnehmer')
        ->assertSee('Behinderungsgruppen')
        ->assertSee('Sportklassen')
        ->assertSee('Rekorde')
        ->assertSee('ÖBSV Cup')
        ->assertSee('ÖBM')
        ->assertSee('ÖJM');
});

it('lässt nicht angeforderte Abschnitte vollständig weg', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', [
            'year' => 2024,
            'sections' => ['overview' => '1'],
        ]))
        ->assertOk()
        ->assertSee('Allgemeiner Überblick')
        ->assertDontSee('Vereinsstatistik')
        ->assertDontSee('Rekorde')
        ->assertDontSee('ÖBSV Cup');
});

it('nummeriert die Abschnitte fortlaufend, unabhängig von der Auswahl', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', [
            'year' => 2024,
            'sections' => ['overview' => '1', 'records' => '1'],
        ]))
        ->assertOk()
        ->assertSee('1. Allgemeiner Überblick')
        ->assertSee('2. Rekorde');
});

it('weist darauf hin, wenn kein Abschnitt ausgewählt wurde', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', ['year' => 2024, 'sections' => []]))
        ->assertOk()
        ->assertSee('kein Abschnitt für den Bericht ausgewählt');
});

// ── Inhalte ──────────────────────────────────────────────────────────────────

it('gibt die Kennzahlen des Berichtsjahres aus', function () {
    $meet = rep13_meet('Testmeet', '2024-06-01');
    $club = Club::create(['name' => 'Testverein', 'nation_id' => rep13_nation()->id]);
    $athlete = rep13_athlete('Muster');

    rep13_start($meet, $athlete, $club);
    rep13_start($meet, $athlete, $club);

    SwimRecord::create([
        'stroke_type_id' => rep13_strokeType()->id, 'athlete_id' => $athlete->id,
        'record_type' => 'AUT', 'sport_class' => 'S9', 'gender' => 'F',
        'distance' => 100, 'swim_time' => 6000, 'set_date' => '2024-06-01',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', ['year' => 2024, 'sections' => rep13_allSections()]))
        ->assertOk()
        ->assertSee('Testmeet')
        ->assertSee('Testverein')
        ->assertSee('Muster, Test');
});

it('führt ausländische Teilnehmer ohne die österreichischen auf', function () {
    $meet = rep13_meet('Testmeet', '2024-06-01');
    $club = Club::create(['name' => 'Testverein', 'nation_id' => rep13_nation()->id]);

    rep13_start($meet, rep13_athlete('Inland'), $club);
    rep13_start($meet, rep13_athlete('Ausland', 'CZE'), $club);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', ['year' => 2024, 'sections' => ['nations' => '1']]));

    $response->assertOk()->assertSee('CZE');
    expect($response->getContent())->not->toContain('Österreich</td>');
});

it('berücksichtigt eine Einschränkung auf ausgewählte Veranstaltungen', function () {
    $selected = rep13_meet('Gewählt', '2024-03-01');
    $other = rep13_meet('Nicht gewählt', '2024-09-01');
    $club = Club::create(['name' => 'Testverein', 'nation_id' => rep13_nation()->id]);

    rep13_start($selected, rep13_athlete('Eins'), $club);
    rep13_start($other, rep13_athlete('Zwei'), $club);

    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', [
            'year' => 2024,
            'meet_ids' => [$selected->id],
            'sections' => ['meets' => '1'],
        ]))
        ->assertOk()
        ->assertSee('Gewählt')
        ->assertDontSee('Nicht gewählt');
});

// ── ÖBM / ÖJM ────────────────────────────────────────────────────────────────

it('wertet die als ÖBM markierten Veranstaltungen gesondert aus', function () {
    $championship = rep13_meet('Staatsmeisterschaft', '2024-05-01');
    $other = rep13_meet('Anderes Meet', '2024-09-01');
    $club = Club::create(['name' => 'Testverein', 'nation_id' => rep13_nation()->id]);

    rep13_start($championship, rep13_athlete('Meister'), $club);
    rep13_start($other, rep13_athlete('Sonstig'), $club);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', [
            'year' => 2024,
            'oebm_meet_ids' => [$championship->id],
            'sections' => ['oebm' => '1'],
        ]));

    $response->assertOk()
        ->assertSee('Österreichische Meisterschaften')
        ->assertSee('Staatsmeisterschaft')
        ->assertSee('Meister, Test')
        ->assertDontSee('Sonstig, Test');
});

it('weist einen Meisterschaftsabschnitt ohne ausgewählte Veranstaltungen als leer aus', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', ['year' => 2024, 'sections' => ['oejm' => '1']]))
        ->assertOk()
        ->assertSee('Österreichische Jugendmeisterschaften')
        ->assertSee('keine Veranstaltungen ausgewählt');
});

it('trennt ÖBM und ÖJM voneinander', function () {
    $oebm = rep13_meet('ÖBM Meet', '2024-05-01');
    $oejm = rep13_meet('ÖJM Meet', '2024-07-01');
    $club = Club::create(['name' => 'Testverein', 'nation_id' => rep13_nation()->id]);

    rep13_start($oebm, rep13_athlete('Aelter'), $club);
    rep13_start($oejm, rep13_athlete('Juenger'), $club);

    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', [
            'year' => 2024,
            'oebm_meet_ids' => [$oebm->id],
            'oejm_meet_ids' => [$oejm->id],
            'sections' => ['oebm' => '1', 'oejm' => '1'],
        ]))
        ->assertOk()
        ->assertSee('ÖBM Meet')
        ->assertSee('ÖJM Meet');
});

// ── Zeitraum ─────────────────────────────────────────────────────────────────

it('wertet nur Veranstaltungen des Berichtsjahres aus', function () {
    $club = Club::create(['name' => 'Testverein', 'nation_id' => rep13_nation()->id]);
    rep13_start(rep13_meet('Meet 2024', '2024-06-01'), rep13_athlete('Eins'), $club);
    rep13_start(rep13_meet('Meet 2023', '2023-06-01'), rep13_athlete('Zwei'), $club);

    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report', ['year' => 2023, 'sections' => ['meets' => '1']]))
        ->assertOk()
        ->assertSee('Meet 2023')
        ->assertDontSee('Meet 2024');
});

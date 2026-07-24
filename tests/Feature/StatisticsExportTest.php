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
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

uses()->group('statistik-p15');

// ── Helpers ──────────────────────────────────────────────────────────────────

function exp15_nation(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code === 'AUT' ? 'Österreich' : $code, 'name_en' => $code, 'is_active' => true]
    );
}

function exp15_strokeType(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        [
            'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
            'category' => 'standard', 'is_active' => true,
        ]
    );
}

function exp15_seed(): Meet
{
    $meet = Meet::create([
        'name' => 'Testmeet', 'nation_id' => exp15_nation()->id,
        'course' => 'LCM', 'start_date' => '2024-06-01',
    ]);

    $club = Club::create(['name' => 'Testverein', 'nation_id' => exp15_nation()->id]);

    $athlete = Athlete::create([
        'first_name' => 'Anna', 'last_name' => 'Muster', 'gender' => 'F',
        'birth_date' => '2000-01-01', 'nation_id' => exp15_nation()->id, 'is_active' => true,
    ]);

    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => exp15_strokeType()->id,
        'distance' => 100, 'gender' => 'A', 'relay_count' => 1,
    ]);

    Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'swim_time' => 6000,
    ]);

    SwimRecord::create([
        'stroke_type_id' => exp15_strokeType()->id, 'athlete_id' => $athlete->id,
        'record_type' => 'AUT', 'sport_class' => 'S9', 'gender' => 'F',
        'distance' => 100, 'swim_time' => 6000, 'set_date' => '2024-06-01',
    ]);

    return $meet;
}

function exp15_allSections(): array
{
    return array_fill_keys(ReportConfiguration::SECTION_KEYS, '1');
}

/** Lädt den Inhalt einer heruntergeladenen Datei und entfernt sie danach. */
function exp15_content(TestResponse $response): string
{
    /** @var BinaryFileResponse $download nur BinaryFileResponse kennt getFile() */
    $download = $response->baseResponse;

    $path = $download->getFile()->getPathname();
    $content = file_get_contents($path);
    @unlink($path);

    return $content;
}

// ── Zugriff ──────────────────────────────────────────────────────────────────

it('leitet nicht angemeldete Besucher bei beiden Exporten auf die Login-Seite', function (string $route) {
    $this->get(route($route, ['year' => 2024]))->assertRedirect(route('login'));
})->with(['statistics.report.xlsx', 'statistics.report.csv']);

it('lehnt einen Aufruf ohne Jahr ab', function (string $route) {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertSessionHasErrors('year');
})->with(['statistics.report.xlsx', 'statistics.report.csv']);

// ── Excel ────────────────────────────────────────────────────────────────────

it('liefert eine Excel-Datei mit dem Berichtsjahr im Dateinamen', function () {
    exp15_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.xlsx', ['year' => 2024, 'sections' => exp15_allSections()]));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('jahresbericht-2024.xlsx')
        ->and(exp15_content($response))->toStartWith('PK'); // xlsx ist ein ZIP-Container
});

it('erzeugt auch ohne Daten eine gültige Excel-Datei', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.xlsx', ['year' => 2024, 'sections' => exp15_allSections()]));

    $response->assertOk();
    expect(exp15_content($response))->toStartWith('PK');
});

it('exportiert auf Wunsch nur einen einzelnen Bereich', function () {
    exp15_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.xlsx', [
            'year' => 2024,
            'sections' => exp15_allSections(),
            'section' => 'clubs',
        ]));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('jahresbericht-2024-clubs.xlsx')
        ->and(exp15_content($response))->toStartWith('PK');
});

it('ignoriert einen unbekannten Bereich und exportiert vollständig', function () {
    exp15_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.xlsx', [
            'year' => 2024,
            'sections' => exp15_allSections(),
            'section' => 'gibtesnicht',
        ]));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('jahresbericht-2024.xlsx');
});

// ── CSV ──────────────────────────────────────────────────────────────────────

it('liefert eine CSV-Datei mit den Daten des Berichts', function () {
    exp15_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.csv', [
            'year' => 2024,
            'sections' => exp15_allSections(),
            'section' => 'clubs',
        ]));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('jahresbericht-2024-clubs.csv');

    $content = exp15_content($response);

    expect($content)->toContain('Testverein')
        ->and($content)->toContain('Verein')
        ->and($content)->toContain(';'); // Semikolon als Trennzeichen für Excel
});

it('schreibt die CSV mit BOM, damit Excel Umlaute korrekt anzeigt', function () {
    exp15_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.csv', ['year' => 2024, 'sections' => ['overview' => '1']]));

    $response->assertOk();
    expect(exp15_content($response))->toStartWith("\u{FEFF}");
});

it('enthält im CSV mehrerer Tabellen deren Überschriften', function () {
    exp15_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.csv', [
            'year' => 2024,
            'sections' => ['records' => '1'],
            'section' => 'records',
        ]));

    $response->assertOk();
    $content = exp15_content($response);

    expect($content)->toContain('Rekorde')
        ->and($content)->toContain('Rekordarten')
        ->and($content)->toContain('Rekorde je Sportler');
});

it('berücksichtigt die Einschränkung auf ausgewählte Veranstaltungen', function () {
    $meet = exp15_seed();

    // Zweite Veranstaltung, die durch die Auswahl herausfallen muss.
    Meet::create([
        'name' => 'Anderes Meet', 'nation_id' => exp15_nation()->id,
        'course' => 'LCM', 'start_date' => '2024-09-01',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.csv', [
            'year' => 2024,
            'meet_ids' => [$meet->id],
            'sections' => ['meets' => '1'],
            'section' => 'meets',
        ]));

    $response->assertOk();
    $content = exp15_content($response);

    expect($content)->toContain('Testmeet')
        ->and($content)->not->toContain('Anderes Meet');
});

it('exportiert einen abgewählten Abschnitt nicht', function () {
    exp15_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.csv', ['year' => 2024, 'sections' => ['overview' => '1']]));

    $response->assertOk();
    $content = exp15_content($response);

    // Nur der Überblick war aktiviert — die Vereinstabelle darf fehlen.
    expect($content)->toContain('Überblick')
        ->and($content)->not->toContain('Testverein');
});

it('schreibt Nullwerte als 0 und nicht als leere Zelle', function () {
    exp15_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.csv', ['year' => 2024, 'sections' => ['overview' => '1']]));

    $response->assertOk();
    $content = exp15_content($response);

    // Ohne strictNullComparison vergliche PhpSpreadsheet lose gegen null;
    // 0 == null ist in PHP wahr, wodurch jede Null als Leerzelle verschwände.
    expect($content)->toContain('"Vereine (Ausland)";"0"')
        ->and($content)->toContain('"EXH";"0"');
});

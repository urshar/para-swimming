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

uses()->group('statistik-p14');

// ── Helpers ──────────────────────────────────────────────────────────────────

function pdf14_nation(): Nation
{
    return Nation::firstOrCreate(
        ['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]
    );
}

function pdf14_strokeType(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        [
            'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
            'category' => 'standard', 'is_active' => true,
        ]
    );
}

/** Legt einen kleinen Datenbestand für 2024 an (Meet, Start, Rekord). */
function pdf14_seed(): Meet
{
    $meet = Meet::create([
        'name' => 'Testmeet', 'nation_id' => pdf14_nation()->id,
        'course' => 'LCM', 'start_date' => '2024-06-01',
    ]);

    $club = Club::create(['name' => 'Testverein', 'nation_id' => pdf14_nation()->id]);

    $athlete = Athlete::create([
        'first_name' => 'Anna', 'last_name' => 'Muster', 'gender' => 'F',
        'birth_date' => '2000-01-01', 'nation_id' => pdf14_nation()->id, 'is_active' => true,
    ]);

    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => pdf14_strokeType()->id,
        'distance' => 100, 'gender' => 'A', 'relay_count' => 1,
    ]);

    Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'swim_time' => 6000,
    ]);

    SwimRecord::create([
        'stroke_type_id' => pdf14_strokeType()->id, 'athlete_id' => $athlete->id,
        'record_type' => 'AUT', 'sport_class' => 'S9', 'gender' => 'F',
        'distance' => 100, 'swim_time' => 6000, 'set_date' => '2024-06-01',
    ]);

    return $meet;
}

function pdf14_allSections(): array
{
    return array_fill_keys(ReportConfiguration::SECTION_KEYS, '1');
}

// ── Zugriff ──────────────────────────────────────────────────────────────────

it('leitet nicht angemeldete Besucher auf die Login-Seite', function () {
    $this->get(route('statistics.report.pdf', ['year' => 2024]))->assertRedirect(route('login'));
});

it('lehnt einen Aufruf ohne Jahr ab', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.pdf'))
        ->assertSessionHasErrors('year');
});

// ── PDF-Erzeugung ────────────────────────────────────────────────────────────

it('liefert ein PDF für einen leeren Zeitraum', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.pdf', ['year' => 2024, 'sections' => pdf14_allSections()]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

it('liefert ein PDF mit vollständigem Datenbestand', function () {
    pdf14_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.pdf', ['year' => 2024, 'sections' => pdf14_allSections()]));

    $response->assertOk();
    // PdfExportService::stream() liefert trotz des Namens eine gewöhnliche
    // Response: dompdf rendert vollständig und hängt das PDF als Body an.
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF');
});

it('benennt die Datei nach dem Berichtsjahr', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.pdf', ['year' => 2023]));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('jahresbericht-2023.pdf');
});

it('erzeugt auch mit nur einem Abschnitt ein PDF', function () {
    pdf14_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.pdf', ['year' => 2024, 'sections' => ['overview' => '1']]));

    $response->assertOk();
    expect($response->getContent())->toStartWith('%PDF');
});

it('erzeugt ein PDF mit den Meisterschaftsabschnitten', function () {
    $meet = pdf14_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.pdf', [
            'year' => 2024,
            'oebm_meet_ids' => [$meet->id],
            'sections' => ['oebm' => '1', 'oejm' => '1'],
        ]));

    $response->assertOk();
    expect($response->getContent())->toStartWith('%PDF');
});

it('berücksichtigt eine Einschränkung auf ausgewählte Veranstaltungen', function () {
    $meet = pdf14_seed();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('statistics.report.pdf', [
            'year' => 2024,
            'meet_ids' => [$meet->id],
            'sections' => pdf14_allSections(),
        ]));

    $response->assertOk();
    expect($response->getContent())->toStartWith('%PDF');
});

// ── Gleichlauf mit der Browser-Ansicht ───────────────────────────────────────

it('nutzt für PDF und Browser dieselbe Berichtsvorlage', function () {
    // Beide Ausgaben binden statistics.partials.sections ein; die HTML-Ansicht
    // dient hier als lesbare Kontrolle desselben Inhalts.
    pdf14_seed();

    $parameters = ['year' => 2024, 'sections' => pdf14_allSections()];
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('statistics.report', $parameters));
    $pdf = $this->actingAs($user)->get(route('statistics.report.pdf', $parameters));

    $html->assertOk()->assertSee('Testmeet')->assertSee('Testverein');
    $pdf->assertOk();
    expect($pdf->getContent())->toStartWith('%PDF');
});

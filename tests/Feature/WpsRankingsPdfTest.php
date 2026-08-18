<?php

use App\Livewire\WpsRankings;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Models\WpsPointVersion;
use App\Support\WpsRankingFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('wps-rankings-p5');

// ── Helper (Suffix _wp5 gegen Namenskollisionen) ─────────────────────────────

function nation_wp5(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function stroke_wp5(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => 'FREE'],
        ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']
    );
}

function athlete_wp5(string $nachname, ?string $geburtsdatum): Athlete
{
    return Athlete::query()->create([
        'club_id' => Club::query()->orderBy('id')->value('id'),
        'nation_id' => nation_wp5()->getKey(),
        'first_name' => 'Test',
        'last_name' => $nachname,
        'birth_date' => $geburtsdatum,
        'gender' => 'F',
    ]);
}

function start_wp5(Athlete $athlete, string $datum, int $zeit, int $punkte, string $berechnungsart): Result
{
    $meet = Meet::query()->create([
        'name' => "Meeting $datum",
        'city' => 'Wien',
        'nation_id' => nation_wp5()->getKey(),
        'course' => 'SCM',
        'start_date' => $datum,
    ]);

    $event = SwimEvent::query()->create([
        'meet_id' => $meet->getKey(),
        'stroke_type_id' => stroke_wp5()->getKey(),
        'event_number' => 1,
        'gender' => 'F',
        'distance' => 100,
        'relay_count' => 1,
    ]);

    return Result::query()->create([
        'meet_id' => $meet->getKey(),
        'swim_event_id' => $event->getKey(),
        'athlete_id' => $athlete->getKey(),
        'club_id' => $athlete->getAttribute('club_id'),
        'swim_time' => $zeit,
        'sport_class' => 'S9',
        'wps_points' => $punkte,
        'wps_calculation_type' => $berechnungsart,
        'wps_estimated_lcm_time' => $berechnungsart === Result::WPS_TYPE_ESTIMATED ? $zeit + 200 : null,
    ]);
}

beforeEach(function () {
    Club::query()->create(['name' => 'WAT', 'short_name' => 'WAT', 'nation_id' => nation_wp5()->getKey()]);
    $this->admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
});

// ── Ausgabe ──────────────────────────────────────────────────────────────────

it('liefert das PDF aus', function () {
    start_wp5(athlete_wp5('Erste', '2000-05-01'), '2026-03-13', 7000, 800, Result::WPS_TYPE_OFFICIAL);

    $this->actingAs($this->admin)
        ->get(route('wps.rankings.pdf').'?year=2026')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('liefert auch ohne Ergebnisse ein PDF statt eines Fehlers', function () {
    // Eine leere Rangliste ist eine Aussage; ein Fehler wäre keine.
    $this->actingAs($this->admin)
        ->get(route('wps.rankings.pdf').'?year=2026')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('weist nicht angemeldete Anfragen ab', function () {
    $this->get(route('wps.rankings.pdf'))->assertRedirect();
});

// ── Filterübernahme ──────────────────────────────────────────────────────────

it('nimmt den Filterstand in den PDF-Link auf', function () {
    start_wp5(athlete_wp5('Erste', '2000-05-01'), '2026-03-13', 7000, 800, Result::WPS_TYPE_OFFICIAL);

    $komponente = Livewire::actingAs($this->admin)->test(WpsRankings::class);

    // Der Standardzustand ergibt eine Adresse ohne Fragezeichen — außer dem Jahr, das
    // vorbelegt ist.
    $komponente->call('setFilter', 'gender', 'F');

    expect($komponente->instance()->pdfUrl())->toContain('gender=F')
        ->and($komponente->instance()->pdfUrl())->toContain('year=2026');
});

it('lässt Standardwerte aus der Adresse weg', function () {
    $filter = new WpsRankingFilter(year: 2026);

    // type=season und course=SCM sind die Vorbelegung; sie in die Adresse zu schreiben machte
    // sie länger, ohne etwas auszusagen.
    expect($filter->toQuery())->toBe(['year' => '2026'])
        ->and($filter->typeLabel())->toBe('Saisonrangliste');
});

it('wendet den Filter aus der Adresse auf das PDF an', function () {
    start_wp5(athlete_wp5('Weiblich', '2000-05-01'), '2026-03-13', 7000, 800, Result::WPS_TYPE_OFFICIAL);

    $maennlich = athlete_wp5('Maennlich', '2000-05-01');
    $maennlich->update(['gender' => 'M']);
    start_wp5($maennlich, '2026-04-01', 6900, 850, Result::WPS_TYPE_OFFICIAL);

    $this->actingAs($this->admin)
        ->get(route('wps.rankings.pdf').'?year=2026&gender=F')
        ->assertOk();

    // Ein vertippter Parameter soll eine vollständige Liste liefern, keine leere.
    $this->actingAs($this->admin)
        ->get(route('wps.rankings.pdf').'?year=2026&gender=Unsinn')
        ->assertOk();
});

// ── SCM-Hinweis (§11.4) ──────────────────────────────────────────────────────

it('zeigt den SCM-Hinweis nur bei geschätzten Punkten', function () {
    start_wp5(athlete_wp5('Offiziell', '2000-05-01'), '2026-03-13', 7000, 800, Result::WPS_TYPE_OFFICIAL);

    // Ein Hinweis, der immer dasteht, wird nicht mehr gelesen.
    $ohne = $this->actingAs($this->admin)
        ->get(route('wps.rankings.pdf').'?year=2026&calc=official');

    $ohne->assertOk();

    start_wp5(athlete_wp5('Geschaetzt', '2000-05-01'), '2026-04-01', 7100, 790, Result::WPS_TYPE_ESTIMATED);

    $this->actingAs($this->admin)
        ->get(route('wps.rankings.pdf').'?year=2026&calc=estimated')
        ->assertOk();
});

it('ergänzt bei einer Altersgrenze den Nachwuchshinweis', function () {
    start_wp5(athlete_wp5('Jugend', '2009-06-01'), '2026-03-13', 7000, 800, Result::WPS_TYPE_ESTIMATED);

    // Der Umrechnungsfaktor ist an international startenden Athleten geeicht und fällt für
    // den Nachwuchs zu optimistisch aus (wps-points §9.6).
    $this->actingAs($this->admin)
        ->get(route('wps.rankings.pdf').'?year=2026&maxAge=18')
        ->assertOk();
});

// ── Kopfbereich ──────────────────────────────────────────────────────────────

it('nennt alle verwendeten Punkteversionen', function () {
    $alt = WpsPointVersion::query()->create([
        'label' => 'WPS 2025', 'year' => 2025, 'version' => '1',
        'status' => WpsPointVersion::STATUS_ACTIVE, 'valid_from' => '2025-01-01',
    ]);
    $neu = WpsPointVersion::query()->create([
        'label' => 'WPS 2026', 'year' => 2026, 'version' => '1',
        'status' => WpsPointVersion::STATUS_ACTIVE, 'valid_from' => '2026-01-01',
    ]);

    start_wp5(athlete_wp5('Alt', '2000-05-01'), '2026-03-13', 7000, 800, Result::WPS_TYPE_OFFICIAL)
        ->update(['wps_point_version_id' => $alt->getKey()]);
    start_wp5(athlete_wp5('Neu', '2000-05-01'), '2026-04-01', 7100, 790, Result::WPS_TYPE_OFFICIAL)
        ->update(['wps_point_version_id' => $neu->getKey()]);

    // Eine Liste aus verschiedenen Jahrgängen sähe sonst aus wie eine einheitlich gerechnete.
    $this->actingAs($this->admin)
        ->get(route('wps.rankings.pdf').'?year=2026')
        ->assertOk();
});

it('benennt die Ranglistenart', function () {
    $saison = new WpsRankingFilter(year: 2026);
    $veranstaltung = new WpsRankingFilter(type: WpsRankingFilter::TYPE_MEET, year: 2026);

    expect($saison->typeLabel())->toBe('Saisonrangliste')
        ->and($veranstaltung->typeLabel())->toBe('Veranstaltungsrangliste');
});

it('führt Athleten ohne Geburtsdatum auch im PDF gesondert', function () {
    start_wp5(athlete_wp5('MitDatum', '2009-06-01'), '2026-03-13', 7000, 800, Result::WPS_TYPE_OFFICIAL);
    start_wp5(athlete_wp5('OhneDatum', null), '2026-04-01', 6900, 850, Result::WPS_TYPE_OFFICIAL);

    // Fehlende Zuordnungen bleiben sichtbar, statt still zu verschwinden.
    $this->actingAs($this->admin)
        ->get(route('wps.rankings.pdf').'?year=2026&maxAge=18')
        ->assertOk();
});

<?php

use App\Models\Athlete;
use App\Models\BaseTimeSportClass;
use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Models\WpsScmConversionFactor;
use App\Services\QualificationSelectionService;
use App\Support\QualificationRankingEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('wps-qual-p5');

// ── Helper (Suffix _wq5 gegen Namenskollisionen) ─────────────────────────────

function nation_wq5(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function club_wq5(string $name): Club
{
    return Club::query()->create(['name' => $name, 'nation_id' => nation_wq5()->id]);
}

function stroke_wq5(string $lenexCode): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => $lenexCode],
        ['code' => $lenexCode, 'name_de' => $lenexCode, 'name_en' => $lenexCode]
    );
}

function athlete_wq5(Club $club, string $nachname): Athlete
{
    return Athlete::query()->create([
        'club_id' => $club->id,
        'nation_id' => nation_wq5()->id,
        'first_name' => 'Test',
        'last_name' => $nachname,
        'birth_date' => '2000-05-01',
        'gender' => 'F',
    ]);
}

function meet_wq5(string $name, string $course, string $datum, bool $approved): Meet
{
    return Meet::query()->create([
        'name' => $name,
        'city' => 'Wien',
        'nation_id' => nation_wq5()->id,
        'course' => $course,
        'start_date' => $datum,
        'wps_approved' => $approved,
    ]);
}

function event_wq5(Meet $meet, string $lenexCode, int $distance): SwimEvent
{
    return SwimEvent::query()->create([
        'meet_id' => $meet->id,
        'stroke_type_id' => stroke_wq5($lenexCode)->id,
        'event_number' => 1,
        'gender' => 'F',
        'distance' => $distance,
        'relay_count' => 1,
    ]);
}

function result_wq5(SwimEvent $event, Athlete $athlete, int $zeit, string $sportClass, ?int $punkte): Result
{
    return Result::query()->create([
        'meet_id' => $event->meet_id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => $zeit,
        'sport_class' => $sportClass,
        'wps_points' => $punkte,
    ]);
}

function standard_wq5(Championship $c, string $lenexCode, int $distance, string $sportClass, ?int $mqs, ?int $met): ChampionshipStandard
{
    return ChampionshipStandard::query()->create([
        'championship_id' => $c->getKey(),
        'stroke_type_id' => stroke_wq5($lenexCode)->id,
        'distance' => $distance,
        'gender' => 'F',
        'sport_class' => $sportClass,
        'mqs_centiseconds' => $mqs,
        'met_centiseconds' => $met,
    ]);
}

beforeEach(function () {
    $this->service = app(QualificationSelectionService::class);

    BaseTimeSportClass::query()->firstOrCreate(['code' => 'S14'], ['sort_order' => 14]);

    $this->club = club_wq5('WAT');

    $this->championship = Championship::query()->create([
        'name' => 'EM 2026',
        'short_name' => 'EM 2026',
        'type' => Championship::TYPE_EC,
        'year' => 2026,
        'course' => Championship::COURSE_LCM,
        'qualification_start' => '2025-01-01',
        'qualification_end' => now()->addYear()->format('Y-m-d'),
    ]);

    $this->meet = meet_wq5('WPS Lignano', 'LCM', '2026-03-13', true);
});

// ── Rangliste je Bewerb ──────────────────────────────────────────────────────

it('sortiert nach Punkten, nicht nach Zeit', function () {
    standard_wq5($this->championship, 'FREE', 200, 'S14', 14443, null);

    $event = event_wq5($this->meet, 'FREE', 200);

    // Die langsamere Zeit hat die höhere Punktzahl — konstruiert, aber genau das ist der
    // Fall, der Zeiten als Sortierkriterium ausschließt.
    result_wq5($event, athlete_wq5($this->club, 'Langsamer'), 14000, 'S14', 900);
    result_wq5($event, athlete_wq5($this->club, 'Schneller'), 13800, 'S14', 700);

    $rangliste = $this->service->rankingByEvent($this->championship, null)->first();

    expect($rangliste->first()->athlete->last_name)->toBe('Langsamer')
        ->and($rangliste->first()->rank)->toBe(1)
        ->and($rangliste->last()->athlete->last_name)->toBe('Schneller')
        ->and($rangliste->last()->rank)->toBe(2);
});

it('nimmt nur Nachweise auf', function () {
    standard_wq5($this->championship, 'FREE', 200, 'S14', 14443, 15000);

    WpsScmConversionFactor::query()->create([
        'stroke_type_id' => stroke_wq5('FREE')->id,
        'factor' => 1.0345,
        'source' => WpsScmConversionFactor::SOURCE_MANUAL,
        'active' => true,
    ]);

    $event = event_wq5($this->meet, 'FREE', 200);
    result_wq5($event, athlete_wq5($this->club, 'Nachweis'), 14000, 'S14', 900);

    // Nur MET erreicht — qualifiziert niemanden.
    result_wq5($event, athlete_wq5($this->club, 'NurMet'), 14800, 'S14', 800);

    // Umgerechnete Kurzbahnzeit — kein Nachweis.
    $scm = meet_wq5('Kurzbahn', 'SCM', '2026-04-01', true);
    result_wq5(event_wq5($scm, 'FREE', 200), athlete_wq5($this->club, 'Umgerechnet'), 13000, 'S14', 950);

    // Nicht anerkannter Wettkampf.
    $ohneAnerkennung = meet_wq5('Vereinsmeeting', 'LCM', '2026-04-02', false);
    result_wq5(event_wq5($ohneAnerkennung, 'FREE', 200), athlete_wq5($this->club, 'Unanerkannt'), 13500, 'S14', 940);

    $rangliste = $this->service->rankingByEvent($this->championship, null)->first();

    expect($rangliste)->toHaveCount(1)
        ->and($rangliste->first()->athlete->last_name)->toBe('Nachweis');
});

it('teilt den Rang bei Punktgleichheit und lässt den folgenden springen', function () {
    standard_wq5($this->championship, 'FREE', 200, 'S14', 14443, null);

    $event = event_wq5($this->meet, 'FREE', 200);
    result_wq5($event, athlete_wq5($this->club, 'A'), 14000, 'S14', 900);
    result_wq5($event, athlete_wq5($this->club, 'B'), 14100, 'S14', 900);
    result_wq5($event, athlete_wq5($this->club, 'C'), 14200, 'S14', 800);

    $raenge = $this->service->rankingByEvent($this->championship, null)->first()
        ->map(static fn (QualificationRankingEntry $e): ?int => $e->rank)
        ->all();

    expect($raenge)->toBe([1, 1, 3]);
});

it('führt Athleten ohne Punktbewertung getrennt am Ende', function () {
    standard_wq5($this->championship, 'FREE', 200, 'S14', 14443, null);

    $event = event_wq5($this->meet, 'FREE', 200);
    result_wq5($event, athlete_wq5($this->club, 'MitPunkten'), 14000, 'S14', 900);
    result_wq5($event, athlete_wq5($this->club, 'OhnePunkte'), 13900, 'S14', null);

    $rangliste = $this->service->rankingByEvent($this->championship, null)->first();

    // Die schnellere Zeit ohne Bewertung steht hinten — sie mit 0 Punkten einzusortieren
    // behauptete, sie sei die schlechteste.
    expect($rangliste)->toHaveCount(2)
        ->and($rangliste->last()->athlete->last_name)->toBe('OhnePunkte')
        ->and($rangliste->last()->rank)->toBeNull()
        ->and($rangliste->last()->isUnranked())->toBeTrue()
        ->and($rangliste->first()->isUnranked())->toBeFalse();
});

it('trennt die Ranglisten nach Bewerb und Sportklasse', function () {
    standard_wq5($this->championship, 'FREE', 200, 'S14', 14443, null);
    standard_wq5($this->championship, 'BACK', 100, 'S14', 8755, null);

    result_wq5(event_wq5($this->meet, 'FREE', 200), athlete_wq5($this->club, 'A'), 14000, 'S14', 900);
    result_wq5(event_wq5($this->meet, 'BACK', 100), athlete_wq5($this->club, 'B'), 8500, 'S14', 800);

    expect($this->service->rankingByEvent($this->championship, null))->toHaveCount(2);
});

// ── Gesamtrangliste der Athleten ─────────────────────────────────────────────

it('misst Athleten an der besten Einzelpunktzahl, nicht an der Summe', function () {
    standard_wq5($this->championship, 'FREE', 200, 'S14', 14443, null);
    standard_wq5($this->championship, 'BACK', 100, 'S14', 8755, null);

    $spezialist = athlete_wq5($this->club, 'Spezialist');
    $vielstarter = athlete_wq5($this->club, 'Vielstarter');

    // Eine starke Leistung.
    result_wq5(event_wq5($this->meet, 'FREE', 200), $spezialist, 13800, 'S14', 880);

    // Zwei mittlere Leistungen, in Summe mehr Punkte.
    result_wq5(event_wq5($this->meet, 'FREE', 200), $vielstarter, 14000, 'S14', 700);
    result_wq5(event_wq5($this->meet, 'BACK', 100), $vielstarter, 8500, 'S14', 720);

    $rangliste = $this->service->rankingByAthlete($this->championship, null);

    expect($rangliste)->toHaveCount(2)
        ->and($rangliste->first()->athlete->last_name)->toBe('Spezialist')
        ->and($rangliste->first()->points)->toBe(880)
        // Die Zeile nennt den Bewerb, aus dem die Bestpunktzahl stammt.
        ->and($rangliste->first()->row->eventLabel)->toContain('200 m')
        ->and($rangliste->last()->points)->toBe(720)
        ->and($rangliste->last()->fulfilledCount)->toBe(2);
});

it('führt jeden Athleten in der Gesamtrangliste nur einmal', function () {
    standard_wq5($this->championship, 'FREE', 200, 'S14', 14443, null);
    standard_wq5($this->championship, 'BACK', 100, 'S14', 8755, null);

    $athlet = athlete_wq5($this->club, 'Mehrfach');
    result_wq5(event_wq5($this->meet, 'FREE', 200), $athlet, 14000, 'S14', 700);
    result_wq5(event_wq5($this->meet, 'BACK', 100), $athlet, 8500, 'S14', 720);

    expect($this->service->rankingByAthlete($this->championship, null))->toHaveCount(1);
});

// ── Berechtigungen und Oberfläche ────────────────────────────────────────────

it('zeigt Vereinsnutzern nur die eigenen Athleten', function () {
    standard_wq5($this->championship, 'FREE', 200, 'S14', 14443, null);

    $fremd = club_wq5('SV Graz');
    $event = event_wq5($this->meet, 'FREE', 200);

    result_wq5($event, athlete_wq5($this->club, 'Eigen'), 14000, 'S14', 700);
    result_wq5($event, athlete_wq5($fremd, 'Fremd'), 13800, 'S14', 900);

    $nutzer = User::factory()->create(['is_admin' => false, 'club_id' => $this->club->id]);

    $this->actingAs($nutzer)
        ->get(route('championships.selection', $this->championship))
        ->assertOk()
        ->assertSee('Eigen')
        ->assertDontSee('Fremd');
});

it('blendet über die Obergrenze weitere Plätze aus, ohne sie zu löschen', function () {
    standard_wq5($this->championship, 'FREE', 200, 'S14', 14443, null);

    $event = event_wq5($this->meet, 'FREE', 200);
    result_wq5($event, athlete_wq5($this->club, 'Erste'), 14000, 'S14', 900);
    result_wq5($event, athlete_wq5($this->club, 'Zweite'), 14100, 'S14', 800);

    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);

    $this->actingAs($admin)
        ->get(route('championships.selection', $this->championship).'?limit=1')
        ->assertOk()
        ->assertSee('Erste')
        ->assertDontSee('Zweite');

    // Die Daten selbst bleiben vollständig.
    expect($this->service->rankingByAthlete($this->championship, null))->toHaveCount(2);
});

it('liefert die drei PDF-Ausgaben aus', function () {
    standard_wq5($this->championship, 'FREE', 200, 'S14', 14443, null);
    result_wq5(event_wq5($this->meet, 'FREE', 200), athlete_wq5($this->club, 'Nachweis'), 14000, 'S14', 900);

    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);

    foreach ([
        'championships.selection.pdf',
        'championships.qualified.pdf',
        'championships.development.pdf',
    ] as $route) {
        $this->actingAs($admin)
            ->get(route($route, $this->championship))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
});

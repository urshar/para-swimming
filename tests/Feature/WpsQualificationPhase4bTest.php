<?php

use App\Livewire\Admin\ChampionshipQualificationTable;
use App\Models\Athlete;
use App\Models\AthleteKaderMembership;
use App\Models\BaseTimeSportClass;
use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\Club;
use App\Models\KaderType;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\QualificationEvaluationService;
use App\Support\QualificationAthleteSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('wps-qual-p4b');

// ── Helper (Suffix _wq4b gegen Namenskollisionen) ────────────────────────────

function nation_wq4b(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function club_wq4b(string $name): Club
{
    return Club::query()->create(['name' => $name, 'nation_id' => nation_wq4b()->id]);
}

function stroke_wq4b(string $lenexCode): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => $lenexCode],
        ['code' => $lenexCode, 'name_de' => $lenexCode, 'name_en' => $lenexCode]
    );
}

function athlete_wq4b(Club $club, string $nachname): Athlete
{
    return Athlete::query()->create([
        'club_id' => $club->id,
        'nation_id' => nation_wq4b()->id,
        'first_name' => 'Test',
        'last_name' => $nachname,
        'birth_date' => '2000-05-01',
        'gender' => 'F',
    ]);
}

function meet_wq4b(string $name, string $datum, bool $approved): Meet
{
    return Meet::query()->create([
        'name' => $name,
        'city' => 'Wien',
        'nation_id' => nation_wq4b()->id,
        'course' => 'LCM',
        'start_date' => $datum,
        'wps_approved' => $approved,
    ]);
}

function event_wq4b(Meet $meet, string $lenexCode, int $distance): SwimEvent
{
    return SwimEvent::query()->create([
        'meet_id' => $meet->id,
        'stroke_type_id' => stroke_wq4b($lenexCode)->id,
        'event_number' => 1,
        'gender' => 'F',
        'distance' => $distance,
        'relay_count' => 1,
    ]);
}

function result_wq4b(SwimEvent $event, Athlete $athlete, int $zeit, string $sportClass, ?int $punkte): Result
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

function standard_wq4b(Championship $c, string $lenexCode, int $distance, string $sportClass, ?int $mqs, ?int $met): ChampionshipStandard
{
    return ChampionshipStandard::query()->create([
        'championship_id' => $c->getKey(),
        'stroke_type_id' => stroke_wq4b($lenexCode)->id,
        'distance' => $distance,
        'gender' => 'F',
        'sport_class' => $sportClass,
        'mqs_centiseconds' => $mqs,
        'met_centiseconds' => $met,
    ]);
}

beforeEach(function () {
    $this->service = app(QualificationEvaluationService::class);

    foreach ([11, 14] as $nummer) {
        BaseTimeSportClass::query()->firstOrCreate(['code' => "S$nummer"], ['sort_order' => $nummer]);
    }

    $this->club = club_wq4b('WAT');

    // Zeitraum läuft noch — damit greift der heutige Tag als Kader-Stichtag.
    $this->championship = Championship::query()->create([
        'name' => 'EM 2026',
        'short_name' => 'EM 2026',
        'type' => Championship::TYPE_EC,
        'year' => 2026,
        'course' => Championship::COURSE_LCM,
        'qualification_start' => '2025-01-01',
        'qualification_end' => now()->addYear()->format('Y-m-d'),
    ]);
});

// ── Zusammenstellung je Athlet ───────────────────────────────────────────────

it('zeigt Bewerbe mit Norm, erfüllte wie offene, und lässt Bewerbe ohne Norm weg', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, null);
    standard_wq4b($this->championship, 'BACK', 100, 'S14', 8755, null);
    // Für 400 Freistil ist keine Norm ausgeschrieben.

    $athlet = athlete_wq4b($this->club, 'Falk');
    $meet = meet_wq4b('WPS Lignano', '2026-03-13', true);

    result_wq4b(event_wq4b($meet, 'FREE', 200), $athlet, 13809, 'S14', 859); // erfüllt
    result_wq4b(event_wq4b($meet, 'BACK', 100), $athlet, 9012, 'S14', 563);  // Norm 87.55 verfehlt
    result_wq4b(event_wq4b($meet, 'FREE', 400), $athlet, 29882, 'S14', 773); // ohne Norm

    $eintrag = $this->service->qualificationOverview($this->championship, null)->sole();

    expect($eintrag->rows)->toHaveCount(2)
        ->and($eintrag->mqsCount())->toBe(1)
        ->and($eintrag->openCount())->toBe(1)
        ->and($eintrag->rows->pluck('eventLabel')->join(', '))->not->toContain('400');
});

it('führt den Leistungsverlauf aller Ergebnisse eines Bewerbs chronologisch', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, null);

    $athlet = athlete_wq4b($this->club, 'Falk');

    $frueh = meet_wq4b('WPS Lignano 2025', '2025-03-14', true);
    $spaet = meet_wq4b('WPS Lignano 2026', '2026-03-13', true);

    result_wq4b(event_wq4b($spaet, 'FREE', 200), $athlet, 13809, 'S14', 859);
    result_wq4b(event_wq4b($frueh, 'FREE', 200), $athlet, 14326, 'S14', 785);

    $zeile = $this->service->qualificationOverview($this->championship, null)->sole()->rows->sole();

    expect($zeile->history)->toHaveCount(2)
        // Chronologisch, nicht nach Zeit — sonst wäre keine Entwicklung ablesbar.
        ->and($zeile->history->first()->swimTime)->toBe(14326)
        ->and($zeile->history->last()->swimTime)->toBe(13809)
        // Die Bestleistung bleibt maßgeblich für den Status.
        ->and($zeile->status->swimTime)->toBe(13809)
        // Verbesserung um 5,17 s
        ->and($zeile->trend())->toBe(-517);
});

it('liefert ohne zweites Ergebnis keine Tendenz statt einer erfundenen', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, null);

    $athlet = athlete_wq4b($this->club, 'Falk');
    $meet = meet_wq4b('WPS Lignano', '2026-03-13', true);
    result_wq4b(event_wq4b($meet, 'FREE', 200), $athlet, 13809, 'S14', 859);

    $zeile = $this->service->qualificationOverview($this->championship, null)->sole()->rows->sole();

    expect($zeile->trend())->toBeNull();
});

it('kennzeichnet je Einzelergebnis, ob es MQS oder MET erreicht', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, 15000);

    $athlet = athlete_wq4b($this->club, 'Falk');
    $meet = meet_wq4b('WPS Lignano', '2026-03-13', true);
    $event = event_wq4b($meet, 'FREE', 200);

    result_wq4b($event, $athlet, 13809, 'S14', 859); // MQS
    result_wq4b($event, $athlet, 14800, 'S14', 700); // nur MET
    result_wq4b($event, $athlet, 15500, 'S14', 600); // keine

    $verlauf = $this->service->qualificationOverview($this->championship, null)->sole()->rows->sole()->history;

    expect($verlauf->firstWhere('swimTime', 13809)->standardLabel())->toBe('MQS')
        ->and($verlauf->firstWhere('swimTime', 14800)->standardLabel())->toBe('MET')
        ->and($verlauf->firstWhere('swimTime', 15500)->standardLabel())->toBeNull();
});

it('übernimmt Platz und Punkte aus dem Ergebnis', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, null);

    $athlet = athlete_wq4b($this->club, 'Falk');
    $meet = meet_wq4b('WPS Lignano', '2026-03-13', true);

    $ergebnis = result_wq4b(event_wq4b($meet, 'FREE', 200), $athlet, 13809, 'S14', 859);
    $ergebnis->update(['place' => 2]);

    $verlauf = $this->service->qualificationOverview($this->championship, null)->sole()->rows->sole()->history;

    expect($verlauf->first()->points)->toBe(859)
        ->and($verlauf->first()->place)->toBe(2);
});

// ── Nur anerkannte Wettkämpfe, nur die Bahnlänge der Meisterschaft ───────────

it('lässt Ergebnisse aus nicht anerkannten Wettkämpfen ganz weg', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, null);

    $athlet = athlete_wq4b($this->club, 'Falk');
    $meet = meet_wq4b('Vereinsmeeting', '2026-03-13', false);
    result_wq4b(event_wq4b($meet, 'FREE', 200), $athlet, 13809, 'S14', 859);

    expect($this->service->qualificationOverview($this->championship, null))->toBeEmpty()
        // In der Förderansicht erscheint es sehr wohl.
        ->and($this->service->evaluate($this->championship, null, null))->toHaveCount(1);
});

// ── Kaderarten ───────────────────────────────────────────────────────────────

it('gruppiert nach Kaderart und ordnet Athleten ohne Zugehörigkeit ans Ende', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, null);

    $top = KaderType::query()->create(['code' => 'TOP', 'name_de' => 'Top', 'sort_order' => 1, 'is_active' => true]);

    $mitKader = athlete_wq4b($this->club, 'MitKader');
    $ohneKader = athlete_wq4b($this->club, 'OhneKader');

    AthleteKaderMembership::query()->create([
        'athlete_id' => $mitKader->id,
        'kader_type_id' => $top->id,
        'valid_from' => '2025-01-01',
    ]);

    $meet = meet_wq4b('WPS Lignano', '2026-03-13', true);
    $event = event_wq4b($meet, 'FREE', 200);

    result_wq4b($event, $mitKader, 13809, 'S14', 859);
    result_wq4b($event, $ohneKader, 14000, 'S14', 830);

    $athleten = $this->service->qualificationOverview($this->championship, null)
        ->sortBy(fn (QualificationAthleteSummary $e): int => $e->kaderSortOrder)
        ->values();

    expect($athleten->first()->kaderName)->toBe('Top')
        ->and($athleten->last()->kaderName)->toBeNull()
        ->and($athleten->last()->kaderSortOrder)->toBe(PHP_INT_MAX);
});

it('verwendet bei laufendem Zeitraum den heutigen Tag als Kader-Stichtag', function () {
    expect($this->service->kaderReferenceDate($this->championship))
        ->toBe(now()->format('Y-m-d'));
});

it('verwendet bei abgelaufenem Zeitraum dessen Ende als Kader-Stichtag', function () {
    $vergangen = Championship::query()->create([
        'name' => 'EM 2024',
        'type' => Championship::TYPE_EC,
        'year' => 2024,
        'qualification_start' => '2023-01-01',
        'qualification_end' => '2024-02-26',
    ]);

    // Sonst stünde bei einer späteren Auswertung eine Kadereinteilung, die es damals
    // nicht gab.
    expect($this->service->kaderReferenceDate($vergangen))->toBe('2024-02-26');
});

it('berücksichtigt eine zum Stichtag abgelaufene Kaderzugehörigkeit nicht', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, null);

    $top = KaderType::query()->create(['code' => 'TOP', 'name_de' => 'Top', 'sort_order' => 1, 'is_active' => true]);
    $athlet = athlete_wq4b($this->club, 'Ehemalig');

    AthleteKaderMembership::query()->create([
        'athlete_id' => $athlet->id,
        'kader_type_id' => $top->id,
        'valid_from' => '2024-01-01',
        'valid_until' => '2024-12-31',
    ]);

    $meet = meet_wq4b('WPS Lignano', '2026-03-13', true);
    result_wq4b(event_wq4b($meet, 'FREE', 200), $athlet, 13809, 'S14', 859);

    expect($this->service->qualificationOverview($this->championship, null)->sole()->kaderName)
        ->toBeNull();
});

// ── Oberfläche ───────────────────────────────────────────────────────────────

it('filtert nach erfüllten und nicht erfüllten Bewerben', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, null);
    standard_wq4b($this->championship, 'BACK', 100, 'S14', 8755, null);

    $athlet = athlete_wq4b($this->club, 'Falk');
    $meet = meet_wq4b('WPS Lignano', '2026-03-13', true);

    result_wq4b(event_wq4b($meet, 'FREE', 200), $athlet, 13809, 'S14', 859); // erfüllt
    result_wq4b(event_wq4b($meet, 'BACK', 100), $athlet, 9012, 'S14', 563);  // Norm 87.55 verfehlt

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(ChampionshipQualificationTable::class, [
            'championship' => $this->championship,
            'clubId' => null,
        ]);

    $eintrag = $komponente->instance()->groups()->flatten()->first();

    expect($komponente->instance()->visibleRows($eintrag))->toHaveCount(2);

    $komponente->call('setFilter', 'fulfilment', 'met');
    expect($komponente->instance()->visibleRows($eintrag))->toHaveCount(1);

    $komponente->call('setFilter', 'fulfilment', 'open');
    expect($komponente->instance()->visibleRows($eintrag))->toHaveCount(1);
});

it('klappt den Leistungsverlauf auf und wieder zu', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, null);

    $athlet = athlete_wq4b($this->club, 'Falk');
    $meet = meet_wq4b('WPS Lignano', '2026-03-13', true);
    result_wq4b(event_wq4b($meet, 'FREE', 200), $athlet, 13809, 'S14', 859);

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(ChampionshipQualificationTable::class, [
            'championship' => $this->championship,
            'clubId' => null,
        ]);

    $komponente->call('toggle', 'abc');
    expect($komponente->instance()->isExpanded('abc'))->toBeTrue();

    $komponente->call('toggle', 'abc');
    expect($komponente->instance()->isExpanded('abc'))->toBeFalse();
});

it('zeigt Vereinsnutzern nur die eigenen Athleten', function () {
    standard_wq4b($this->championship, 'FREE', 200, 'S14', 14443, null);

    $fremd = club_wq4b('SV Graz');
    $meet = meet_wq4b('WPS Lignano', '2026-03-13', true);
    $event = event_wq4b($meet, 'FREE', 200);

    result_wq4b($event, athlete_wq4b($this->club, 'Eigen'), 13809, 'S14', 859);
    result_wq4b($event, athlete_wq4b($fremd, 'Fremd'), 13700, 'S14', 870);

    $nutzer = User::factory()->create(['is_admin' => false, 'club_id' => $this->club->id]);

    $this->actingAs($nutzer)
        ->get(route('championships.qualified', $this->championship))
        ->assertOk()
        ->assertSee('Eigen')
        ->assertDontSee('Fremd');
});

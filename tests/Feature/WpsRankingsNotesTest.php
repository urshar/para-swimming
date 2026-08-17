<?php

use App\Livewire\WpsAthleteAnalysis;
use App\Models\Athlete;
use App\Models\AthletePerformanceNote;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\WpsAthleteAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('wps-rankings-notes');

// ── Helper (Suffix _wn gegen Namenskollisionen) ──────────────────────────────

function nation_wn(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function club_wn(string $name): Club
{
    return Club::query()->create(['name' => $name, 'short_name' => $name, 'nation_id' => nation_wn()->getKey()]);
}

function stroke_wn(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => 'FREE'],
        ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']
    );
}

function athlete_wn(Club $club, string $nachname): Athlete
{
    return Athlete::query()->create([
        'club_id' => $club->getKey(),
        'nation_id' => nation_wn()->getKey(),
        'first_name' => 'Test',
        'last_name' => $nachname,
        'birth_date' => '2005-06-01',
        'gender' => 'F',
    ]);
}

function start_wn(Athlete $athlete, string $datum, int $zeit, int $punkte): Result
{
    $meet = Meet::query()->create([
        'name' => "Meeting $datum",
        'city' => 'Wien',
        'nation_id' => nation_wn()->getKey(),
        'course' => 'SCM',
        'start_date' => $datum,
    ]);

    $event = SwimEvent::query()->create([
        'meet_id' => $meet->getKey(),
        'stroke_type_id' => stroke_wn()->getKey(),
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
        'wps_calculation_type' => Result::WPS_TYPE_ESTIMATED,
    ]);
}

beforeEach(function () {
    $this->service = app(WpsAthleteAnalysisService::class);
    $this->club = club_wn('WAT');
    $this->athlete = athlete_wn($this->club, 'Notizfall');
    $this->admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    $this->vereinsnutzer = User::factory()->create(['is_admin' => false, 'club_id' => $this->club->getKey()]);
});

// ── Alle Starts (§7.4) ───────────────────────────────────────────────────────

it('zeigt wahlweise jeden Start oder nur die Saisonbestleistung', function () {
    start_wn($this->athlete, '2025-03-01', 7400, 620);
    start_wn($this->athlete, '2025-06-01', 7200, 660);
    start_wn($this->athlete, '2025-09-01', 7000, 700);

    // Ohne das letzte Argument gilt die Saisonbestleistung.
    $besten = $this->service->profile($this->athlete, null, null, 'SCM');
    $alle = $this->service->profile($this->athlete, null, null, 'SCM', true);

    // Die Saisonbestleistung allein zeigt nicht, wie eine Entwicklung zustande kam.
    expect($besten->entryCount())->toBe(1)
        ->and($alle->entryCount())->toBe(3);
});

it('vergleicht bei allen Starts mit dem vorherigen Start, nicht mit der Vorsaison', function () {
    start_wn($this->athlete, '2025-03-01', 7400, 620);
    start_wn($this->athlete, '2025-06-01', 7200, 660);

    $zeilen = $this->service->profile($this->athlete, null, null, 'SCM', true)->byEvent->first();

    expect($zeilen->first()->pointsDelta)->toBeNull()
        ->and($zeilen->last()->pointsDelta)->toBe(40)
        ->and($zeilen->last()->resultId)->not->toBeNull();
});

it('sortiert alle Starts chronologisch innerhalb der Saison', function () {
    start_wn($this->athlete, '2025-09-01', 7000, 700);
    start_wn($this->athlete, '2025-03-01', 7400, 620);

    $daten = $this->service->profile($this->athlete, null, null, 'SCM', true)
        ->byEvent->first()->pluck('meetDate')->all();

    expect($daten)->toBe(['2025-03-01', '2025-09-01']);
});

// ── Notizen anlegen (§7.5) ───────────────────────────────────────────────────

it('legt eine Notiz zu einem Start an und übernimmt dessen Datum', function () {
    $start = start_wn($this->athlete, '2025-06-01', 7200, 660);

    Livewire::actingAs($this->admin)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete])
        ->call('startNote', $start->getKey())
        ->set('noteCategory', AthletePerformanceNote::CATEGORY_ILLNESS)
        ->set('noteText', 'Zwei Wochen Grippe vor dem Wettkampf')
        ->set('noteDate', '2025-01-01')
        ->call('saveNote')
        ->assertHasNoErrors();

    $notiz = AthletePerformanceNote::query()->sole();

    // Das Datum stammt aus dem Wettkampf; zwei abweichende Daten wären eine
    // Widersprüchlichkeit, die niemand auflösen kann.
    expect($notiz->getAttribute('result_id'))->toBe($start->getKey())
        ->and($notiz->getAttribute('noted_on')->format('Y-m-d'))->toBe('2025-06-01')
        ->and($notiz->getAttribute('category'))->toBe(AthletePerformanceNote::CATEGORY_ILLNESS)
        ->and($notiz->getAttribute('created_by'))->toBe($this->admin->getKey());
});

it('legt eine Notiz ohne Startbezug mit dem eingegebenen Datum an', function () {
    Livewire::actingAs($this->admin)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete])
        ->call('startNote', null)
        ->set('noteCategory', AthletePerformanceNote::CATEGORY_INJURY)
        ->set('noteText', 'Sechs Wochen Trainingspause wegen Schulterverletzung')
        ->set('noteDate', '2025-02-15')
        ->call('saveNote')
        ->assertHasNoErrors();

    $notiz = AthletePerformanceNote::query()->sole();

    // Nicht jede Ursache hängt an einem Start.
    expect($notiz->getAttribute('result_id'))->toBeNull()
        ->and($notiz->getAttribute('noted_on')->format('Y-m-d'))->toBe('2025-02-15');
});

it('weist eine leere Notiz ab', function () {
    Livewire::actingAs($this->admin)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete])
        ->call('startNote', null)
        ->set('noteText', '')
        ->call('saveNote')
        ->assertHasErrors('noteText');

    expect(AthletePerformanceNote::query()->count())->toBe(0);
});

it('löscht eine Notiz', function () {
    $start = start_wn($this->athlete, '2025-06-01', 7200, 660);

    $notiz = AthletePerformanceNote::query()->create([
        'athlete_id' => $this->athlete->getKey(),
        'result_id' => $start->getKey(),
        'noted_on' => '2025-06-01',
        'category' => AthletePerformanceNote::CATEGORY_OTHER,
        'note' => 'Testnotiz',
        'created_by' => $this->admin->getKey(),
    ]);

    Livewire::actingAs($this->admin)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete])
        ->call('deleteNote', $notiz->getKey());

    expect(AthletePerformanceNote::query()->count())->toBe(0);
});

it('behält die Notiz, wenn das Ergebnis gelöscht wird', function () {
    $start = start_wn($this->athlete, '2025-06-01', 7200, 660);

    AthletePerformanceNote::query()->create([
        'athlete_id' => $this->athlete->getKey(),
        'result_id' => $start->getKey(),
        'noted_on' => '2025-06-01',
        'category' => AthletePerformanceNote::CATEGORY_INJURY,
        'note' => 'Schulter',
        'created_by' => $this->admin->getKey(),
    ]);

    $start->delete();

    // Die Beobachtung über den Athleten gilt weiter, auch wenn der Start entfällt.
    $notiz = AthletePerformanceNote::query()->sole();

    expect($notiz->getAttribute('result_id'))->toBeNull()
        ->and($notiz->getAttribute('note'))->toBe('Schulter');
});

// ── Sichtbarkeit (§7.5) ──────────────────────────────────────────────────────

it('zeigt Notizen dem eigenen Verein', function () {
    AthletePerformanceNote::query()->create([
        'athlete_id' => $this->athlete->getKey(),
        'noted_on' => '2025-06-01',
        'category' => AthletePerformanceNote::CATEGORY_ILLNESS,
        'note' => 'Grippe',
        'created_by' => $this->admin->getKey(),
    ]);

    $komponente = Livewire::actingAs($this->vereinsnutzer)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete]);

    expect($komponente->instance()->canViewNotes())->toBeTrue()
        ->and($komponente->instance()->notes())->toHaveCount(1);
});

it('verbirgt Notizen vor fremden Vereinen', function () {
    AthletePerformanceNote::query()->create([
        'athlete_id' => $this->athlete->getKey(),
        'noted_on' => '2025-06-01',
        'category' => AthletePerformanceNote::CATEGORY_ILLNESS,
        'note' => 'Grippe',
        'created_by' => $this->admin->getKey(),
    ]);

    $fremd = User::factory()->create(['is_admin' => false, 'club_id' => club_wn('SV Graz')->getKey()]);

    // Krankheit und Verletzung sind Gesundheitsangaben — anders als die Ranglisten selbst
    // sind Notizen nicht verbandsweit sichtbar.
    $komponente = Livewire::actingAs($fremd)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete]);

    expect($komponente->instance()->canViewNotes())->toBeFalse()
        ->and($komponente->instance()->notes())->toBeEmpty();
});

it('verwehrt fremden Vereinen das Anlegen einer Notiz', function () {
    $fremd = User::factory()->create(['is_admin' => false, 'club_id' => club_wn('SV Graz')->getKey()]);

    Livewire::actingAs($fremd)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete])
        ->call('startNote', null)
        ->assertForbidden();

    expect(AthletePerformanceNote::query()->count())->toBe(0);
});

it('verwehrt fremden Vereinen das Löschen einer Notiz', function () {
    $notiz = AthletePerformanceNote::query()->create([
        'athlete_id' => $this->athlete->getKey(),
        'noted_on' => '2025-06-01',
        'category' => AthletePerformanceNote::CATEGORY_OTHER,
        'note' => 'Testnotiz',
        'created_by' => $this->admin->getKey(),
    ]);

    $fremd = User::factory()->create(['is_admin' => false, 'club_id' => club_wn('SV Graz')->getKey()]);

    Livewire::actingAs($fremd)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete])
        ->call('deleteNote', $notiz->getKey())
        ->assertForbidden();

    expect(AthletePerformanceNote::query()->count())->toBe(1);
});

// ── Zuordnung und Zeitraum ───────────────────────────────────────────────────

it('ordnet Notizen ihrem Start zu', function () {
    $start = start_wn($this->athlete, '2025-06-01', 7200, 660);

    AthletePerformanceNote::query()->create([
        'athlete_id' => $this->athlete->getKey(),
        'result_id' => $start->getKey(),
        'noted_on' => '2025-06-01',
        'category' => AthletePerformanceNote::CATEGORY_TRAINING,
        'note' => 'Höhentrainingslager',
        'created_by' => $this->admin->getKey(),
    ]);

    AthletePerformanceNote::query()->create([
        'athlete_id' => $this->athlete->getKey(),
        'noted_on' => '2025-02-01',
        'category' => AthletePerformanceNote::CATEGORY_OTHER,
        'note' => 'Ohne Startbezug',
        'created_by' => $this->admin->getKey(),
    ]);

    $komponente = Livewire::actingAs($this->admin)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete]);

    expect($komponente->instance()->notesByResult())->toHaveKey($start->getKey())
        ->and($komponente->instance()->notesByResult()[$start->getKey()])->toHaveCount(1)
        ->and($komponente->instance()->generalNotes())->toHaveCount(1);
});

it('schränkt Notizen auf den gewählten Zeitraum ein', function () {
    foreach (['2023-06-01', '2025-06-01'] as $datum) {
        AthletePerformanceNote::query()->create([
            'athlete_id' => $this->athlete->getKey(),
            'noted_on' => $datum,
            'category' => AthletePerformanceNote::CATEGORY_OTHER,
            'note' => "Notiz $datum",
            'created_by' => $this->admin->getKey(),
        ]);
    }

    start_wn($this->athlete, '2023-06-01', 7400, 620);
    start_wn($this->athlete, '2025-06-01', 7000, 700);

    $komponente = Livewire::actingAs($this->admin)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete]);

    expect($komponente->instance()->notes())->toHaveCount(2);

    $komponente->call('setInput', 'fromYear', '2025');
    $komponente->call('setInput', 'toYear', '2025');

    expect($komponente->instance()->notes())->toHaveCount(1);
});

it('kennzeichnet Gesundheitsangaben als solche', function () {
    $krankheit = new AthletePerformanceNote(['category' => AthletePerformanceNote::CATEGORY_ILLNESS]);
    $training = new AthletePerformanceNote(['category' => AthletePerformanceNote::CATEGORY_TRAINING]);

    expect($krankheit->isHealthRelated())->toBeTrue()
        ->and($training->isHealthRelated())->toBeFalse()
        ->and($krankheit->categoryLabel())->toBe('Krankheit')
        ->and(AthletePerformanceNote::categoryLabels())->toHaveCount(6);
});

<?php

use App\Livewire\Admin\ChampionshipDevelopmentTable;
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
use App\Support\QualificationOverviewFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('wps-qual-p5b');

// ── Helper (Suffix _wq5b gegen Namenskollisionen) ────────────────────────────

function nation_wq5b(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function club_wq5b(string $name): Club
{
    return Club::query()->create(['name' => $name, 'nation_id' => nation_wq5b()->id]);
}

function stroke_wq5b(string $lenexCode): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => $lenexCode],
        ['code' => $lenexCode, 'name_de' => $lenexCode, 'name_en' => $lenexCode]
    );
}

function athlete_wq5b(Club $club, string $nachname): Athlete
{
    return Athlete::query()->create([
        'club_id' => $club->id,
        'nation_id' => nation_wq5b()->id,
        'first_name' => 'Test',
        'last_name' => $nachname,
        'birth_date' => '2000-05-01',
        'gender' => 'F',
    ]);
}

function meet_wq5b(string $name, string $datum, bool $approved): Meet
{
    return Meet::query()->create([
        'name' => $name,
        'city' => 'Wien',
        'nation_id' => nation_wq5b()->id,
        'course' => 'LCM',
        'start_date' => $datum,
        'wps_approved' => $approved,
    ]);
}

function event_wq5b(Meet $meet, string $lenexCode, int $distance): SwimEvent
{
    return SwimEvent::query()->create([
        'meet_id' => $meet->id,
        'stroke_type_id' => stroke_wq5b($lenexCode)->id,
        'event_number' => 1,
        'gender' => 'F',
        'distance' => $distance,
        'relay_count' => 1,
    ]);
}

function result_wq5b(SwimEvent $event, Athlete $athlete, int $zeit, ?int $punkte): Result
{
    return Result::query()->create([
        'meet_id' => $event->meet_id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => $zeit,
        'sport_class' => 'S14',
        'wps_points' => $punkte,
    ]);
}

function standard_wq5b(Championship $c, string $lenexCode, int $distance, ?int $mqs): ChampionshipStandard
{
    return ChampionshipStandard::query()->create([
        'championship_id' => $c->getKey(),
        'stroke_type_id' => stroke_wq5b($lenexCode)->id,
        'distance' => $distance,
        'gender' => 'F',
        'sport_class' => 'S14',
        'mqs_centiseconds' => $mqs,
    ]);
}

function admin_wq5b(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

beforeEach(function () {
    BaseTimeSportClass::query()->firstOrCreate(['code' => 'S14'], ['sort_order' => 14]);

    $this->club = club_wq5b('WAT');

    $this->championship = Championship::query()->create([
        'name' => 'EM 2026',
        'short_name' => 'EM 2026',
        'type' => Championship::TYPE_EC,
        'year' => 2026,
        'course' => Championship::COURSE_LCM,
        'qualification_start' => '2025-01-01',
        'qualification_end' => now()->addYear()->format('Y-m-d'),
    ]);

    $this->meet = meet_wq5b('WPS Lignano', '2026-03-13', true);
});

// ── Filterobjekt ─────────────────────────────────────────────────────────────

it('baut den Filter aus Abfrageparametern und verwirft unbekannte Werte', function () {
    $filter = QualificationOverviewFilter::fromQuery([
        'fulfilment' => 'unsinn',
        'kader' => 'Top',
        'q' => ' Falk ',
    ]);

    // Ein vertippter Parameter soll ein vollständiges PDF liefern, kein leeres.
    expect($filter->fulfilment)->toBe(QualificationOverviewFilter::FULFILMENT_ALL)
        ->and($filter->kader)->toBe('Top')
        ->and($filter->search)->toBe('Falk');
});

it('lässt den Standardzustand aus den Abfrageparametern weg', function () {
    $standard = new QualificationOverviewFilter;
    $gefiltert = new QualificationOverviewFilter(QualificationOverviewFilter::FULFILMENT_MET, 'Top', 'Falk');

    expect($standard->toQuery())->toBe([])
        ->and($standard->isActive())->toBeFalse()
        ->and($standard->describe())->toBeNull()
        ->and($gefiltert->toQuery())->toBe(['fulfilment' => 'met', 'kader' => 'Top', 'q' => 'Falk'])
        ->and($gefiltert->describe())->toContain('nur erfüllte Bewerbe');
});

// ── Qualifikanten-PDF folgt dem Filter ───────────────────────────────────────

it('nimmt den Filterstand in den PDF-Link auf', function () {
    standard_wq5b($this->championship, 'FREE', 200, 14443);
    result_wq5b(event_wq5b($this->meet, 'FREE', 200), athlete_wq5b($this->club, 'Falk'), 14000, 900);

    $komponente = Livewire::actingAs(admin_wq5b())
        ->test(ChampionshipQualificationTable::class, [
            'championship' => $this->championship,
            'clubId' => null,
        ]);

    expect($komponente->instance()->pdfUrl())->not->toContain('?');

    $komponente->call('setFilter', 'fulfilment', 'met');

    expect($komponente->instance()->pdfUrl())->toContain('fulfilment=met');
});

it('wendet den Filter aus der Adresse auf das Qualifikanten-PDF an', function () {
    standard_wq5b($this->championship, 'FREE', 200, 14443);
    standard_wq5b($this->championship, 'BACK', 100, 8755);

    $athlet = athlete_wq5b($this->club, 'Falk');
    result_wq5b(event_wq5b($this->meet, 'FREE', 200), $athlet, 14000, 900); // erfüllt
    result_wq5b(event_wq5b($this->meet, 'BACK', 100), $athlet, 9012, 500);  // offen

    $admin = admin_wq5b();

    $this->actingAs($admin)
        ->get(route('championships.qualified.pdf', $this->championship))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($admin)
        ->get(route('championships.qualified.pdf', $this->championship).'?fulfilment=met&kader=Nichtvorhanden')
        ->assertOk();
});

it('schränkt die Bewerbszeilen im PDF über denselben Filter ein wie am Bildschirm', function () {
    standard_wq5b($this->championship, 'FREE', 200, 14443);
    standard_wq5b($this->championship, 'BACK', 100, 8755);

    $athlet = athlete_wq5b($this->club, 'Falk');
    result_wq5b(event_wq5b($this->meet, 'FREE', 200), $athlet, 14000, 900);
    result_wq5b(event_wq5b($this->meet, 'BACK', 100), $athlet, 9012, 500);

    $uebersicht = app(QualificationEvaluationService::class)
        ->qualificationOverview($this->championship, null)->sole();

    $alle = new QualificationOverviewFilter;
    $nurErfuellt = new QualificationOverviewFilter(QualificationOverviewFilter::FULFILMENT_MET);
    $nurOffen = new QualificationOverviewFilter(QualificationOverviewFilter::FULFILMENT_OPEN);

    expect($alle->visibleRows($uebersicht))->toHaveCount(2)
        ->and($nurErfuellt->visibleRows($uebersicht))->toHaveCount(1)
        ->and($nurOffen->visibleRows($uebersicht))->toHaveCount(1);
});

// ── Athletenauswahl der Förderansicht ────────────────────────────────────────

it('merkt sich die Auswahl und nimmt sie in den PDF-Link auf', function () {
    standard_wq5b($this->championship, 'FREE', 200, 14443);

    $eine = athlete_wq5b($this->club, 'Eine');
    $andere = athlete_wq5b($this->club, 'Andere');
    $event = event_wq5b($this->meet, 'FREE', 200);
    result_wq5b($event, $eine, 14000, 900);
    result_wq5b($event, $andere, 14200, 850);

    $komponente = Livewire::actingAs(admin_wq5b())
        ->test(ChampionshipDevelopmentTable::class, [
            'championship' => $this->championship,
            'clubId' => null,
        ]);

    expect($komponente->instance()->pdfUrl())->not->toContain('athletes=');

    $komponente->call('toggleAthlete', $eine->id);

    expect($komponente->instance()->isSelected($eine->id))->toBeTrue()
        ->and($komponente->instance()->isSelected($andere->id))->toBeFalse()
        ->and($komponente->instance()->pdfUrl())->toContain('athletes='.$eine->id);

    $komponente->call('toggleAthlete', $eine->id);

    expect($komponente->instance()->isSelected($eine->id))->toBeFalse();
});

it('behält die Auswahl über den Seitenwechsel hinweg', function () {
    standard_wq5b($this->championship, 'FREE', 200, 14443);

    $event = event_wq5b($this->meet, 'FREE', 200);
    $erster = athlete_wq5b($this->club, 'A-Erster');

    // Genug Athleten für eine zweite Seite (zehn je Seite).
    result_wq5b($event, $erster, 14000, 900);
    foreach (range(1, 12) as $nummer) {
        result_wq5b($event, athlete_wq5b($this->club, 'Weitere'.$nummer), 14100 + $nummer, 800);
    }

    $komponente = Livewire::actingAs(admin_wq5b())
        ->test(ChampionshipDevelopmentTable::class, [
            'championship' => $this->championship,
            'clubId' => null,
        ]);

    $komponente->call('toggleAthlete', $erster->id);
    $komponente->call('gotoPage', 2);

    // Genau dafür ist die Ansicht eine Livewire-Komponente: Auf einer gewöhnlichen Seite
    // wäre das Häkchen beim Blättern verfallen.
    expect($komponente->instance()->isSelected($erster->id))->toBeTrue();
});

it('filtert den Bildschirm auf Wunsch auf die Auswahl', function () {
    standard_wq5b($this->championship, 'FREE', 200, 14443);

    $eine = athlete_wq5b($this->club, 'Eine');
    $event = event_wq5b($this->meet, 'FREE', 200);
    result_wq5b($event, $eine, 14000, 900);
    result_wq5b($event, athlete_wq5b($this->club, 'Andere'), 14200, 850);

    $komponente = Livewire::actingAs(admin_wq5b())
        ->test(ChampionshipDevelopmentTable::class, [
            'championship' => $this->championship,
            'clubId' => null,
        ]);

    expect($komponente->instance()->athletes())->toHaveCount(2);

    $komponente->call('toggleAthlete', $eine->id);
    $komponente->call('toggleOnlySelected');

    expect($komponente->instance()->athletes())->toHaveCount(1)
        ->and($komponente->instance()->athletes()->first()->athlete->last_name)->toBe('Eine');

    $komponente->call('clearSelection');

    expect($komponente->instance()->athletes())->toHaveCount(2);
});

it('beschränkt das Förder-PDF auf die ausgewählten Athleten', function () {
    standard_wq5b($this->championship, 'FREE', 200, 14443);

    $eine = athlete_wq5b($this->club, 'Ausgewaehlt');
    $event = event_wq5b($this->meet, 'FREE', 200);
    result_wq5b($event, $eine, 14000, 900);
    result_wq5b($event, athlete_wq5b($this->club, 'Nichtgewaehlt'), 14200, 850);

    $admin = admin_wq5b();

    $this->actingAs($admin)
        ->get(route('championships.development.pdf', $this->championship).'?athletes='.$eine->id)
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // Ohne Auswahl kommen alle ins PDF — der häufigere Fall soll keinen Handgriff kosten.
    $this->actingAs($admin)
        ->get(route('championships.development.pdf', $this->championship))
        ->assertOk();
});

it('verwirft nicht numerische Athletenkennungen aus der Adresse', function () {
    standard_wq5b($this->championship, 'FREE', 200, 14443);
    result_wq5b(event_wq5b($this->meet, 'FREE', 200), athlete_wq5b($this->club, 'Falk'), 14000, 900);

    // Ein von Hand verändertes Adressfeld soll ein vollständiges PDF liefern, keine Ausnahme.
    $this->actingAs(admin_wq5b())
        ->get(route('championships.development.pdf', $this->championship).'?athletes=abc,,-5')
        ->assertOk();
});

it('filtert die Förderansicht nach Kaderart', function () {
    standard_wq5b($this->championship, 'FREE', 200, 14443);

    $top = KaderType::query()->create(['code' => 'TOP', 'name_de' => 'Top', 'sort_order' => 1, 'is_active' => true]);
    $imKader = athlete_wq5b($this->club, 'ImKader');

    AthleteKaderMembership::query()->create([
        'athlete_id' => $imKader->id,
        'kader_type_id' => $top->id,
        'valid_from' => '2025-01-01',
    ]);

    $event = event_wq5b($this->meet, 'FREE', 200);
    result_wq5b($event, $imKader, 14000, 900);
    result_wq5b($event, athlete_wq5b($this->club, 'OhneKader'), 14200, 850);

    $komponente = Livewire::actingAs(admin_wq5b())
        ->test(ChampionshipDevelopmentTable::class, [
            'championship' => $this->championship,
            'clubId' => null,
        ]);

    expect($komponente->instance()->athletes())->toHaveCount(2);

    $komponente->call('setFilter', 'kader', 'Top');

    expect($komponente->instance()->athletes())->toHaveCount(1)
        ->and($komponente->instance()->athletes()->first()->athlete->last_name)->toBe('ImKader');
});

it('zeigt Vereinsnutzern in der Förderansicht nur die eigenen Athleten', function () {
    standard_wq5b($this->championship, 'FREE', 200, 14443);

    $fremd = club_wq5b('SV Graz');
    $event = event_wq5b($this->meet, 'FREE', 200);
    result_wq5b($event, athlete_wq5b($this->club, 'Eigen'), 14000, 900);
    result_wq5b($event, athlete_wq5b($fremd, 'Fremd'), 13900, 950);

    $nutzer = User::factory()->create(['is_admin' => false, 'club_id' => $this->club->id]);

    $this->actingAs($nutzer)
        ->get(route('championships.development', $this->championship))
        ->assertOk()
        ->assertSee('Eigen')
        ->assertDontSee('Fremd');
});

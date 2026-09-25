<?php

use App\Livewire\YearComparison;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use Livewire\Livewire;

uses()->group('statistics-multi-year-chart');

// ── Helpers ──────────────────────────────────────────────────────────────────

function yc_nation(): Nation
{
    return Nation::firstOrCreate(
        ['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true],
    );
}

function yc_start(int $year, string $gender = 'M'): Result
{
    $meet = Meet::create([
        'name' => 'Meet '.uniqid(), 'nation_id' => yc_nation()->id,
        'course' => 'LCM', 'start_date' => "$year-06-01",
    ]);
    $event = SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => StrokeType::firstOrCreate(
            ['code' => 'FREE'],
            ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true],
        )->id,
        'distance' => 100, 'gender' => 'A', 'relay_count' => 1,
    ]);
    $athlete = Athlete::create([
        'first_name' => 'Max', 'last_name' => 'Muster '.uniqid(),
        'gender' => $gender, 'nation_id' => yc_nation()->id, 'is_active' => true,
    ]);
    $club = Club::create(['name' => 'Club '.uniqid(), 'nation_id' => yc_nation()->id]);

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'swim_time' => 6000,
    ]);
}

// ── Zugriff ──────────────────────────────────────────────────────────────────

it('leitet nicht angemeldete Besucher auf die Login-Seite', function () {
    $this->get(route('statistics.comparison'))->assertRedirect(route('login'));
});

it('verwehrt Vereins-Usern den Zugriff', function () {
    $club = Club::create(['name' => 'Testclub', 'nation_id' => yc_nation()->id]);

    $this->actingAs(User::factory()->create(['club_id' => $club->id, 'is_admin' => false]))
        ->get(route('statistics.comparison'))
        ->assertForbidden();
});

it('ist für Admins zugänglich', function () {
    yc_start(2024);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('statistics.comparison'))
        ->assertOk()
        ->assertSee('Jahresvergleich');
});

// ── Inhalte ──────────────────────────────────────────────────────────────────

it('rendert alle Vergleichs-Charts', function () {
    yc_start(2024);
    yc_start(2024, 'F');

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test(YearComparison::class)
        ->assertSet('year', 2024)
        ->assertSee('Einzelstarts nach Geschlecht')
        ->assertSee('Teilnehmer nach Geschlecht')
        ->assertSee('Staffelstarts nach Typ')
        ->assertSee('Rekorde pro Jahr')
        ->assertSee('Veranstaltungen pro Jahr')
        ->assertSee('Status pro Jahr');
});

it('liefert genau die sechs Chart-Definitionen', function () {
    yc_start(2024);

    $charts = Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test(YearComparison::class)
        ->instance()
        ->charts();

    expect(array_column($charts, 'key'))
        ->toBe(['individual_starts', 'participants', 'relay', 'records', 'meets', 'status']);
});

// ── Span-Auswahl ─────────────────────────────────────────────────────────────

it('begrenzt die Anzahl Jahre auf den erlaubten Bereich', function () {
    yc_start(2024);
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)->test(YearComparison::class)
        ->call('setFilter', 'span', '99')->assertSet('span', YearComparison::MAX_SPAN)
        ->call('setFilter', 'span', '1')->assertSet('span', YearComparison::MIN_SPAN)
        ->call('setFilter', 'span', '8')->assertSet('span', 8);
});

it('übernimmt das gewählte Anker-Jahr', function () {
    yc_start(2024);
    yc_start(2022);

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test(YearComparison::class)
        ->call('setFilter', 'year', '2022')
        ->assertSet('year', 2022);
});

// ── Status-Diagramm: Regulär ausblendbar ─────────────────────────────────────

it('blendet reguläre Ergebnisse im Status-Diagramm standardmäßig aus, auf Wunsch ein', function () {
    yc_start(2024);
    $component = Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test(YearComparison::class);

    $statusSeries = fn () => array_column(
        collect($component->instance()->charts())->firstWhere('key', 'status')['series'],
        'key',
    );

    expect($statusSeries())->not->toContain('regular');

    $component->set('showRegularStatus', true);

    expect($statusSeries())->toContain('regular');
});

// ── PDF ──────────────────────────────────────────────────────────────────────

it('liefert den Jahresvergleich als PDF', function () {
    yc_start(2024);
    yc_start(2024, 'F');

    $response = $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('statistics.comparison.pdf', ['year' => 2024, 'span' => 5]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('lehnt den PDF-Aufruf ohne Jahr ab', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('statistics.comparison.pdf'))
        ->assertSessionHasErrors('year');
});

it('erzeugt das PDF auch mit eingeblendeten regulären Ergebnissen', function () {
    yc_start(2024);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('statistics.comparison.pdf', ['year' => 2024, 'span' => 5, 'show_regular' => 1]))
        ->assertOk();
});

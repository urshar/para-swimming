<?php

use App\Livewire\WpsClubRanking;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\WpsClubRankingService;
use App\Support\WpsClubRankingConfiguration;
use App\Support\WpsClubRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('wps-rankings-p7');

// ── Helper (Suffix _wc7 gegen Namenskollisionen) ─────────────────────────────

function nation_wc7(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function club_wc7(string $name): Club
{
    return Club::query()->create([
        'name' => $name, 'short_name' => $name, 'nation_id' => nation_wc7()->getKey(),
    ]);
}

function stroke_wc7(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => 'FREE'],
        ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']
    );
}

function athlete_wc7(Club $club, string $nachname): Athlete
{
    return Athlete::query()->create([
        'club_id' => $club->getKey(),
        'nation_id' => nation_wc7()->getKey(),
        'first_name' => 'Test',
        'last_name' => $nachname,
        'birth_date' => '2000-05-01',
        'gender' => 'F',
    ]);
}

function start_wc7(Athlete $athlete, int $punkte, int $distance): Result
{
    $meet = Meet::query()->create([
        'name' => "Meeting $distance-$punkte",
        'city' => 'Wien',
        'nation_id' => nation_wc7()->getKey(),
        'course' => 'SCM',
        'start_date' => '2026-05-01',
    ]);

    $event = SwimEvent::query()->create([
        'meet_id' => $meet->getKey(),
        'stroke_type_id' => stroke_wc7()->getKey(),
        'event_number' => 1,
        'gender' => 'F',
        'distance' => $distance,
        'relay_count' => 1,
    ]);

    return Result::query()->create([
        'meet_id' => $meet->getKey(),
        'swim_event_id' => $event->getKey(),
        'athlete_id' => $athlete->getKey(),
        'club_id' => $athlete->getAttribute('club_id'),
        'swim_time' => 7000,
        'sport_class' => 'S9',
        'wps_points' => $punkte,
        'wps_calculation_type' => Result::WPS_TYPE_ESTIMATED,
    ]);
}

beforeEach(function () {
    $this->service = app(WpsClubRankingService::class);
    $this->filter = new WpsRankingFilter(year: 2026);

    $this->gross = club_wc7('Grossverein');
    $this->klein = club_wc7('Kleinverein');

    // Der große Verein: drei mittelmäßige Athleten.
    foreach ([600, 620, 640] as $index => $punkte) {
        start_wc7(athlete_wc7($this->gross, "Gross$index"), $punkte, 100);
    }

    // Der kleine Verein: ein sehr starker Athlet.
    start_wc7(athlete_wc7($this->klein, 'Star'), 900, 100);
});

// ── Bewertungsmethoden (§9) ──────────────────────────────────────────────────

it('bevorzugt bei der Summe den großen Verein', function () {
    $config = new WpsClubRankingConfiguration(WpsClubRankingConfiguration::METHOD_SUM);

    $rangliste = $this->service->ranking($this->filter, $config, null);

    // 1860 gegen 900 — die Summe belohnt Breite.
    expect($rangliste->first()->club->name)->toBe('Grossverein')
        ->and($rangliste->first()->value)->toBe(1860.0)
        ->and($rangliste->last()->value)->toBe(900.0);
});

it('bevorzugt beim Durchschnitt den kleinen Verein', function () {
    $config = new WpsClubRankingConfiguration(WpsClubRankingConfiguration::METHOD_AVERAGE);

    $rangliste = $this->service->ranking($this->filter, $config, null);

    // 900 gegen 620 — deshalb beide Methoden, statt sich für eine zu entscheiden.
    expect($rangliste->first()->club->name)->toBe('Kleinverein')
        ->and($rangliste->first()->value)->toBe(900.0)
        ->and($rangliste->last()->value)->toBe(620.0);
});

it('zählt bei der Schwellenmethode die Leistungen darüber', function () {
    $config = new WpsClubRankingConfiguration(
        WpsClubRankingConfiguration::METHOD_COUNT,
        threshold: 620,
    );

    $rangliste = $this->service->ranking($this->filter, $config, null);
    $gross = $rangliste->firstWhere(fn (WpsClubRankingEntry $e): bool => $e->club->name === 'Grossverein');
    $klein = $rangliste->firstWhere(fn (WpsClubRankingEntry $e): bool => $e->club->name === 'Kleinverein');

    // 620 und 640 erreichen die Schwelle, 600 nicht.
    expect($gross->value)->toBe(2.0)
        ->and($klein->value)->toBe(1.0);
});

it('nimmt je Athlet nur die eingestellte Zahl an Leistungen', function () {
    $athlet = Athlete::query()->where('last_name', 'Star')->sole();
    start_wc7($athlet, 800, 200);

    $eine = new WpsClubRankingConfiguration(countedPerAthlete: 1);
    $zwei = new WpsClubRankingConfiguration(countedPerAthlete: 2);

    $mitEiner = $this->service->ranking($this->filter, $eine, null)
        ->firstWhere(fn (WpsClubRankingEntry $e): bool => $e->club->name === 'Kleinverein');
    $mitZwei = $this->service->ranking($this->filter, $zwei, null)
        ->firstWhere(fn (WpsClubRankingEntry $e): bool => $e->club->name === 'Kleinverein');

    expect($mitEiner->value)->toBe(900.0)
        ->and($mitZwei->value)->toBe(1700.0);
});

it('mittelt über die eingegangenen Leistungen, nicht über die Athleten', function () {
    $athlet = Athlete::query()->where('last_name', 'Star')->sole();
    start_wc7($athlet, 800, 200);

    $config = new WpsClubRankingConfiguration(
        WpsClubRankingConfiguration::METHOD_AVERAGE,
        countedPerAthlete: 2,
    );

    $klein = $this->service->ranking($this->filter, $config, null)
        ->firstWhere(fn (WpsClubRankingEntry $e): bool => $e->club->name === 'Kleinverein');

    // Zählte man durch die Athletenzahl, ergäbe "beste zwei" einen doppelt so hohen Wert wie
    // "beste eine" — das wäre kein Durchschnitt.
    expect($klein->value)->toBe(850.0);
});

// ── Mindestzahl ──────────────────────────────────────────────────────────────

it('führt Vereine unter der Mindestzahl ohne Rang, aber sichtbar', function () {
    $config = new WpsClubRankingConfiguration(minEntriesPerClub: 3);

    $rangliste = $this->service->ranking($this->filter, $config, null);
    $klein = $rangliste->firstWhere(fn (WpsClubRankingEntry $e): bool => $e->club->name === 'Kleinverein');

    // Ein Verein mit einem einzigen starken Athleten soll sichtbar bleiben, statt still zu
    // verschwinden.
    expect($rangliste)->toHaveCount(2)
        ->and($klein->isBelowMinimum())->toBeTrue()
        ->and($klein->rank)->toBeNull()
        ->and($rangliste->first()->rank)->toBe(1);
});

// ── Aufschlüsselung ──────────────────────────────────────────────────────────

it('schlüsselt den Vereinswert nach Athleten auf', function () {
    $config = new WpsClubRankingConfiguration;

    $gross = $this->service->ranking($this->filter, $config, null)
        ->firstWhere(fn (WpsClubRankingEntry $e): bool => $e->club->name === 'Grossverein');

    // Eine Vereinssumme ohne Aufschlüsselung ist nicht prüfbar.
    expect($gross->details)->toHaveCount(3)
        ->and($gross->athleteCount)->toBe(3)
        ->and($gross->entryCount)->toBe(3)
        ->and($gross->details->first()->contribution)->toBe(640.0)
        ->and($gross->details->sum(fn ($d): float => $d->contribution))->toBe(1860.0);
});

// ── Gleichstand ──────────────────────────────────────────────────────────────

it('teilt den Rang bei gleichem Wert', function () {
    $dritter = club_wc7('Gleichauf');
    start_wc7(athlete_wc7($dritter, 'Gleich'), 900, 100);

    $raenge = $this->service->ranking($this->filter, new WpsClubRankingConfiguration, null)
        ->map(static fn (WpsClubRankingEntry $e): ?int => $e->rank)
        ->all();

    // Grossverein 1860, dann zwei Vereine mit 900.
    expect($raenge)->toBe([1, 2, 2]);
});

// ── Sichtbarkeit (§9, [R2]) ──────────────────────────────────────────────────

it('zeigt Vereinsnutzern nur den eigenen Verein', function () {
    $rangliste = $this->service->ranking($this->filter, new WpsClubRankingConfiguration, $this->klein->getKey());

    // Die einzige Ansicht des Moduls mit dieser Einschränkung.
    expect($rangliste)->toHaveCount(1)
        ->and($rangliste->first()->club->name)->toBe('Kleinverein');
});

it('überschreibt eine Vereinsauswahl im Filter durch die Sichtbarkeitsregel', function () {
    $mitFremdemVerein = new WpsRankingFilter(year: 2026, clubId: $this->gross->getKey());

    $rangliste = $this->service->ranking(
        $mitFremdemVerein,
        new WpsClubRankingConfiguration,
        $this->klein->getKey(),
    );

    // Die Sichtbarkeitsregel hat Vorrang — sonst ließe sich über den Filter ein fremder
    // Verein einsehen.
    expect($rangliste)->toHaveCount(1)
        ->and($rangliste->first()->club->name)->toBe('Kleinverein');
});

// ── Einstellungen ────────────────────────────────────────────────────────────

it('verwirft unbrauchbare Werte aus der Adresse', function () {
    $config = WpsClubRankingConfiguration::fromQuery([
        'method' => 'unsinn',
        'counted' => '-3',
        'threshold' => 'abc',
    ]);

    expect($config->method)->toBe(WpsClubRankingConfiguration::METHOD_SUM)
        ->and($config->countedPerAthlete)->toBe(1)
        ->and($config->threshold)->toBe(WpsClubRankingConfiguration::DEFAULT_THRESHOLD);
});

it('lässt Standardwerte aus der Adresse weg', function () {
    $standard = new WpsClubRankingConfiguration;
    $abweichend = new WpsClubRankingConfiguration(
        WpsClubRankingConfiguration::METHOD_COUNT,
        threshold: 700,
    );

    expect($standard->toQuery())->toBe([])
        ->and($abweichend->toQuery())->toBe(['method' => 'count', 'threshold' => '700']);
});

it('nennt die Rechenweise in der Beschreibung', function () {
    // Summe und Durchschnitt ergeben völlig verschiedene Reihenfolgen; eine Liste ohne Angabe
    // ihrer Rechenweise ist nicht deutbar.
    expect((new WpsClubRankingConfiguration)->describe())->toContain('Summe')
        ->and((new WpsClubRankingConfiguration(WpsClubRankingConfiguration::METHOD_AVERAGE))->describe())
        ->toContain('Durchschnitt')
        ->and((new WpsClubRankingConfiguration(WpsClubRankingConfiguration::METHOD_COUNT, threshold: 700))->describe())
        ->toContain('700');
});

// ── Oberfläche ───────────────────────────────────────────────────────────────

it('zeigt die Auswertung samt Abgrenzungshinweis', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('wps.clubs'))
        ->assertOk()
        ->assertSee('keine offizielle Wertung');
});

it('beschränkt die Ansicht für Vereinsnutzer', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false, 'club_id' => $this->klein->getKey()]))
        ->get(route('wps.clubs'))
        ->assertOk()
        ->assertSee('Kleinverein')
        ->assertDontSee('Grossverein');
});

it('liefert das PDF aus', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('wps.clubs.pdf').'?year=2026')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('schaltet die Methode über die Komponente um', function () {
    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsClubRanking::class);

    expect($komponente->instance()->ranked()->first()->club->name)->toBe('Grossverein');

    $komponente->call('setInput', 'method', WpsClubRankingConfiguration::METHOD_AVERAGE);

    expect($komponente->instance()->ranked()->first()->club->name)->toBe('Kleinverein')
        ->and($komponente->instance()->pdfUrl())->toContain('method=average');
});

it('klappt die Aufschlüsselung auf und wieder zu', function () {
    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsClubRanking::class);

    $komponente->call('toggle', $this->gross->getKey());

    expect($komponente->instance()->isExpanded($this->gross->getKey()))->toBeTrue();

    $komponente->call('toggle', $this->gross->getKey());

    expect($komponente->instance()->isExpanded($this->gross->getKey()))->toBeFalse();
});

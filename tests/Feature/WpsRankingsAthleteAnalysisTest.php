<?php

use App\Livewire\WpsAthleteAnalysis;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\WpsAthleteAnalysisService;
use App\Support\WpsRankingFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('wps-rankings-p4');

// ── Helper (Suffix _wr4 gegen Namenskollisionen) ─────────────────────────────

function nation_wr4(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function stroke_wr4(string $lenexCode): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => $lenexCode],
        ['code' => $lenexCode, 'name_de' => $lenexCode, 'name_en' => $lenexCode]
    );
}

function athlete_wr4(string $nachname): Athlete
{
    return Athlete::query()->create([
        'club_id' => Club::query()->orderBy('id')->value('id'),
        'nation_id' => nation_wr4()->id,
        'first_name' => 'Test',
        'last_name' => $nachname,
        'birth_date' => '2005-06-01',
        'gender' => 'F',
    ]);
}

function meet_wr4(string $name, string $datum, string $course): Meet
{
    return Meet::query()->create([
        'name' => $name,
        'city' => 'Wien',
        'nation_id' => nation_wr4()->id,
        'course' => $course,
        'start_date' => $datum,
    ]);
}

function event_wr4(Meet $meet, string $lenexCode, int $distance): SwimEvent
{
    return SwimEvent::query()->create([
        'meet_id' => $meet->id,
        'stroke_type_id' => stroke_wr4($lenexCode)->id,
        'event_number' => 1,
        'gender' => 'F',
        'distance' => $distance,
        'relay_count' => 1,
    ]);
}

function result_wr4(SwimEvent $event, Athlete $athlete, int $zeit, int $punkte, string $sportClass): Result
{
    return Result::query()->create([
        'meet_id' => $event->meet_id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => $zeit,
        'sport_class' => $sportClass,
        'wps_points' => $punkte,
        'wps_calculation_type' => Result::WPS_TYPE_ESTIMATED,
    ]);
}

/**
 * Ein Ergebnis in einer bestimmten Saison.
 *
 * Sportklasse und Strecke werden bewusst ohne Vorgabewerte übergeben: In diesen Tests sind
 * sie die eigentliche Aussage — ein Klassenwechsel oder zwei verschiedene Bewerbe.
 */
function saison_wr4(Athlete $athlete, int $jahr, int $zeit, int $punkte, string $sportClass, int $distance): Result
{
    $meet = meet_wr4("Meeting $jahr-$distance", "$jahr-05-01", 'SCM');

    return result_wr4(event_wr4($meet, 'FREE', $distance), $athlete, $zeit, $punkte, $sportClass);
}

beforeEach(function () {
    $this->service = app(WpsAthleteAnalysisService::class);
    Club::query()->create(['name' => 'WAT', 'short_name' => 'WAT', 'nation_id' => nation_wr4()->id]);
    $this->athlete = athlete_wr4('Entwicklung');
});

// ── Beste Leistung je Saison (§7.3) ──────────────────────────────────────────

it('nimmt je Saison und Bewerb die beste Leistung', function () {
    $meet = meet_wr4('Zwei Starts', '2025-05-01', 'SCM');
    $event = event_wr4($meet, 'FREE', 100);

    result_wr4($event, $this->athlete, 7200, 650, 'S9');
    result_wr4($event, $this->athlete, 7000, 700, 'S9');

    $profil = $this->service->profile($this->athlete, null, null);
    $zeilen = $profil->byEvent->first();

    expect($zeilen)->toHaveCount(1)
        ->and($zeilen->first()->points)->toBe(700);
});

it('führt je Saison eine eigene Zeile', function () {
    saison_wr4($this->athlete, 2023, 7500, 600, 'S9', 100);
    saison_wr4($this->athlete, 2024, 7300, 650, 'S9', 100);
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    $zeilen = $this->service->profile($this->athlete, null, null)->byEvent->first();

    expect($zeilen)->toHaveCount(3)
        ->and($zeilen->pluck('year')->all())->toBe([2023, 2024, 2025]);
});

// ── Differenz zur Vorsaison ──────────────────────────────────────────────────

it('errechnet die Differenz zur Vorsaison in Punkten und Zeit', function () {
    saison_wr4($this->athlete, 2024, 7300, 650, 'S9', 100);
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    $zeilen = $this->service->profile($this->athlete, null, null)->byEvent->first();

    expect($zeilen->first()->pointsDelta)->toBeNull()
        ->and($zeilen->last()->pointsDelta)->toBe(50)
        // Negativ heißt schneller geworden.
        ->and($zeilen->last()->timeDelta)->toBe(-300)
        ->and($zeilen->last()->improved())->toBeTrue()
        ->and($zeilen->last()->formattedPointsDelta())->toBe('+50');
});

it('weist eine Verschlechterung als solche aus', function () {
    saison_wr4($this->athlete, 2024, 7000, 700, 'S9', 100);
    saison_wr4($this->athlete, 2025, 7300, 650, 'S9', 100);

    $letzte = $this->service->profile($this->athlete, null, null)->byEvent->first()->last();

    expect($letzte->improved())->toBeFalse()
        ->and($letzte->formattedPointsDelta())->toBe("\u{2212}50")
        ->and($letzte->formattedTimeDelta())->toBe('+3,00 s');
});

it('bildet bei einem Klassenwechsel keine Differenz', function () {
    saison_wr4($this->athlete, 2024, 7300, 650, 'S9', 100);
    saison_wr4($this->athlete, 2025, 7000, 850, 'S8', 100);

    $letzte = $this->service->profile($this->athlete, null, null)->byEvent->first()->last();

    // Eine Verbesserung um 200 Punkte, die allein aus einer Umklassifizierung stammt, wäre
    // eine Falschaussage über die Entwicklung.
    expect($letzte->classChanged)->toBeTrue()
        ->and($letzte->pointsDelta)->toBeNull()
        ->and($letzte->timeDelta)->toBeNull()
        ->and($letzte->hasComparison())->toBeFalse();
});

// ── Sportklassen-Historie (§7.2) ─────────────────────────────────────────────

it('weist einen Klassenwechsel im Profil aus', function () {
    saison_wr4($this->athlete, 2024, 7300, 650, 'S9', 100);
    saison_wr4($this->athlete, 2025, 7000, 850, 'S8', 100);

    $profil = $this->service->profile($this->athlete, null, null);

    // Sportklassen werden nicht historisiert; aus dem Stammsatz wäre der Wechsel nicht
    // erkennbar.
    expect($profil->hasClassChange())->toBeTrue()
        ->and($profil->changedCategories())->toHaveKey('S')
        ->and($profil->changedCategories()['S'])->toBe(['S8', 'S9']);
});

it('meldet keinen Wechsel bei verschiedenen Kategorien derselben Nummer', function () {
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);
    saison_wr4($this->athlete, 2025, 9000, 650, 'SB9', 200);

    $profil = $this->service->profile($this->athlete, null, null);

    // S9 und SB9 sind verschiedene Kategorien, kein Wechsel — die Präfixprüfung muss SB vor
    // S erkennen, sonst liefen die Kategorien zusammen.
    expect($profil->hasClassChange())->toBeFalse()
        ->and($profil->sportClassesByCategory)->toHaveKeys(['S', 'SB']);
});

// ── Gliederung und Sortierung ────────────────────────────────────────────────

it('sortiert die Bewerbe nach der besten erreichten Punktzahl', function () {
    saison_wr4($this->athlete, 2025, 7000, 600, 'S9', 100);
    saison_wr4($this->athlete, 2025, 15000, 800, 'S9', 200);

    $bewerbe = $this->service->profile($this->athlete, null, null)->byEvent->keys()->all();

    // Der stärkste Bewerb steht oben — das ist die Reihenfolge, in der man ein Profil liest.
    expect($bewerbe[0])->toContain('200 m')
        ->and($bewerbe[1])->toContain('100 m');
});

it('liefert für einen Athleten ohne Ergebnisse ein leeres Profil', function () {
    $ohne = athlete_wr4('OhneStarts');

    $profil = $this->service->profile($ohne, null, null);

    expect($profil->isEmpty())->toBeTrue()
        ->and($profil->bestPoints())->toBeNull()
        ->and($profil->firstYear)->toBeNull()
        ->and($profil->hasClassChange())->toBeFalse();
});

// ── Zeitraum ─────────────────────────────────────────────────────────────────

it('zeigt ohne Einschränkung die gesamte Historie', function () {
    saison_wr4($this->athlete, 2019, 8000, 500, 'S9', 100);
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    $profil = $this->service->profile($this->athlete, null, null);

    expect($profil->firstYear)->toBe(2019)
        ->and($profil->lastYear)->toBe(2025)
        ->and($profil->entryCount())->toBe(2);
});

it('schränkt auf den gewählten Zeitraum ein', function () {
    saison_wr4($this->athlete, 2019, 8000, 500, 'S9', 100);
    saison_wr4($this->athlete, 2024, 7300, 650, 'S9', 100);
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    $profil = $this->service->profile($this->athlete, 2024, 2025);

    expect($profil->entryCount())->toBe(2)
        ->and($profil->firstYear)->toBe(2024);
});

it('nennt nur Jahre mit gewerteten Ergebnissen', function () {
    saison_wr4($this->athlete, 2023, 7500, 600, 'S9', 100);
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    expect($this->service->yearsWithResults($this->athlete))->toBe([2025, 2023]);
});

// ── Oberfläche ───────────────────────────────────────────────────────────────

it('zeigt das Profil allen angemeldeten Nutzern', function () {
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    $this->actingAs(User::factory()->create(['is_admin' => false, 'club_id' => Club::query()->value('id')]))
        ->get(route('wps.athletes.show', $this->athlete))
        ->assertOk()
        ->assertSee('Entwicklung');
});

it('verlinkt von der Athletenseite auf die WPS-Analyse', function () {
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    // Kein eigener Sucheinstieg: Wer auf der Athletenseite steht, hat ihn bereits
    // ausgewählt — eine zweite Athletenliste daneben wäre überflüssig.
    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('athletes.show', $this->athlete))
        ->assertOk()
        ->assertSee(route('wps.athletes.show', $this->athlete));
});

it('verlinkt aus der Athletenliste auf die WPS-Analyse', function () {
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('athletes.index'))
        ->assertOk()
        ->assertSee(route('wps.athletes.show', $this->athlete));
});

it('liefert das PDF aus', function () {
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('wps.athletes.pdf', $this->athlete))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('setzt den Zeitraum über die Komponente und hebt ihn wieder auf', function () {
    saison_wr4($this->athlete, 2019, 8000, 500, 'S9', 100);
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete]);

    expect($komponente->instance()->profile()->entryCount())->toBe(2);

    $komponente->call('setInput', 'fromYear', '2025');

    expect($komponente->instance()->profile()->entryCount())->toBe(1);

    $komponente->call('resetPeriod');

    expect($komponente->instance()->profile()->entryCount())->toBe(2);
});

it('zieht das Zeitraumende nach, wenn der Beginn dahinter geschoben wird', function () {
    saison_wr4($this->athlete, 2019, 8000, 500, 'S9', 100);
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete]);

    $komponente->call('setInput', 'toYear', '2019');
    $komponente->call('setInput', 'fromYear', '2025');

    expect($komponente->instance()->profile()->firstYear)->toBe(2025);
});

it('nimmt den Zeitraum in den PDF-Link auf', function () {
    saison_wr4($this->athlete, 2025, 7000, 700, 'S9', 100);

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete]);

    expect($komponente->instance()->pdfUrl())->toContain('course='.WpsRankingFilter::COURSE_MIXED);

    $komponente->call('setInput', 'fromYear', '2025');

    expect($komponente->instance()->pdfUrl())->toContain('from=2025');
});

it('trennt Lang- und Kurzbahn auf Wunsch', function () {
    result_wr4(event_wr4(meet_wr4('Kurzbahn', '2025-05-01', 'SCM'), 'FREE', 100), $this->athlete, 7000, 700, 'S9');
    result_wr4(event_wr4(meet_wr4('Langbahn', '2025-06-01', 'LCM'), 'FREE', 100), $this->athlete, 7200, 720, 'S9');

    // Ohne Angabe gilt MIXED — beide Bahnlängen gemeinsam.
    $beide = $this->service->profile($this->athlete, null, null);
    $nurScm = $this->service->profile($this->athlete, null, null, WpsRankingFilter::COURSE_SCM);

    // Bei gemeinsamer Betrachtung bleibt je Saison und Bewerb die bessere Leistung.
    expect($beide->entryCount())->toBe(1)
        ->and($beide->byEvent->first()->first()->points)->toBe(720)
        ->and($nurScm->byEvent->first()->first()->points)->toBe(700);
});

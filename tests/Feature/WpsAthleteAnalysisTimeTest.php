<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\WpsAthleteAnalysisService;
use App\Services\WpsChartService;
use App\Support\WpsRankingFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('wps-analysis-time');

// ── Helper (Suffix _wt gegen Namenskollisionen) ──────────────────────────────

function nation_wt(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function stroke_wt(string $lenexCode): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => $lenexCode],
        ['code' => $lenexCode, 'name_de' => $lenexCode, 'name_en' => $lenexCode]
    );
}

function start_wt(
    Athlete $athlete,
    string $datum,
    int $zeit,
    ?int $punkte,
    string $sportClass,
    string $course,
    int $distance,
): Result {
    $meet = Meet::query()->create([
        'name' => "Meeting $datum",
        'city' => 'Wien',
        'nation_id' => nation_wt()->getKey(),
        'course' => $course,
        'start_date' => $datum,
    ]);

    $event = SwimEvent::query()->create([
        'meet_id' => $meet->getKey(),
        'stroke_type_id' => stroke_wt('FREE')->getKey(),
        'event_number' => 1,
        'gender' => 'M',
        'distance' => $distance,
        'relay_count' => 1,
    ]);

    return Result::query()->create([
        'meet_id' => $meet->getKey(),
        'swim_event_id' => $event->getKey(),
        'athlete_id' => $athlete->getKey(),
        'club_id' => $athlete->getAttribute('club_id'),
        'swim_time' => $zeit,
        'sport_class' => $sportClass,
        'wps_points' => $punkte,
    ]);
}

beforeEach(function () {
    $this->service = app(WpsAthleteAnalysisService::class);
    $this->chartService = app(WpsChartService::class);

    $club = Club::query()->create([
        'name' => 'WAT', 'short_name' => 'WAT', 'nation_id' => nation_wt()->getKey(),
    ]);

    $this->athlete = Athlete::query()->create([
        'club_id' => $club->getKey(),
        'nation_id' => nation_wt()->getKey(),
        'first_name' => 'Test',
        'last_name' => 'Verlauf',
        'birth_date' => '1997-03-09',
        'gender' => 'M',
    ]);
});

// ── Ergebnisse ohne Punkte ───────────────────────────────────────────────────

it('nimmt Ergebnisse ohne WPS-Punkte auf', function () {
    start_wt($this->athlete, '2024-03-01', 7400, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7200, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2025-03-01', 7000, 620, 'S4', 'SCM', 100);

    // Die Ranglisten-Auswahl hätte zwei von drei verworfen — Ergebnisse, die für die
    // Verlaufsfrage vollwertig sind.
    $profil = $this->service->profile($this->athlete, null, null, 'SCM', true);

    expect($profil->entryCount())->toBe(3);
});

it('lässt die Punktzahl leer statt sie auf null zu setzen', function () {
    start_wt($this->athlete, '2024-03-01', 7400, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7200, null, 'S4', 'SCM', 100);

    $zeilen = $this->service->profile($this->athlete, null, null, 'SCM', true)->byEvent->first();

    expect($zeilen->first()->hasPoints())->toBeFalse()
        ->and($zeilen->first()->points)->toBeNull();
});

it('liefert ohne jede Punktzahl keine beste Punktzahl', function () {
    start_wt($this->athlete, '2024-03-01', 7400, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7200, null, 'S4', 'SCM', 100);

    $profil = $this->service->profile($this->athlete, null, null, 'SCM', true);

    // "0 Punkte" wäre eine Aussage, die niemand getroffen hat.
    expect($profil->bestPoints())->toBeNull();
});

// ── Zeit als Maß ─────────────────────────────────────────────────────────────

it('nimmt je Saison die schnellste Zeit, nicht die höchste Punktzahl', function () {
    // Die langsamere Zeit trägt die höhere Punktzahl — konstruiert, aber es zeigt, welches
    // Maß entscheidet.
    start_wt($this->athlete, '2025-03-01', 7400, 900, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2025-06-01', 7000, 600, 'S4', 'SCM', 100);

    $zeile = $this->service->profile($this->athlete, null, null, 'SCM')->byEvent->first()->sole();

    expect($zeile->swimTime)->toBe(7000);
});

it('misst die Verbesserung an der Zeit', function () {
    start_wt($this->athlete, '2024-03-01', 7400, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7200, null, 'S4', 'SCM', 100);

    $letzte = $this->service->profile($this->athlete, null, null, 'SCM', true)->byEvent->first()->last();

    expect($letzte->timeDelta)->toBe(-200)
        ->and($letzte->improved())->toBeTrue()
        ->and($letzte->formattedTimeDelta())->toBe("\u{2212}2,00 s")
        // Ohne Punkte auf beiden Seiten gibt es keine Punktdifferenz.
        ->and($letzte->pointsDelta)->toBeNull();
});

it('bildet die Punktdifferenz nur, wenn beide Werte vorliegen', function () {
    start_wt($this->athlete, '2024-03-01', 7400, 600, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7200, 650, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-09-01', 7100, null, 'S4', 'SCM', 100);

    $zeilen = $this->service->profile($this->athlete, null, null, 'SCM', true)->byEvent->first();

    expect($zeilen[1]->pointsDelta)->toBe(50)
        ->and($zeilen[2]->pointsDelta)->toBeNull()
        // Die Zeitdifferenz bleibt in jedem Fall.
        ->and($zeilen[2]->timeDelta)->toBe(-100);
});

it('vergleicht keine Zeiten über einen Bahnlängenwechsel hinweg', function () {
    start_wt($this->athlete, '2024-03-01', 7000, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7300, null, 'S4', 'LCM', 100);

    $letzte = $this->service->profile($this->athlete, null, null, WpsRankingFilter::COURSE_MIXED, true)
        ->byEvent->first()->last();

    // 1:10 auf Kurzbahn und 1:13 auf Langbahn sind verschiedene Leistungen, keine
    // Verschlechterung.
    expect($letzte->timeDelta)->toBeNull();
});

// ── Grafik ───────────────────────────────────────────────────────────────────

it('zeichnet die Zeitachse umgekehrt — schneller liegt oben', function () {
    start_wt($this->athlete, '2024-03-01', 7400, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7000, null, 'S4', 'SCM', 100);

    $zeilen = $this->service->profile($this->athlete, null, null, 'SCM', true)->byEvent->first();
    $serie = $this->chartService->series('100 m FREE', $zeilen, collect(), WpsChartService::METRIC_TIME);

    $langsam = $serie->points->first();
    $schnell = $serie->points->last();

    // Eine Zeitkurve, die bei einer Verbesserung nach unten zeigte, würde jeder falsch lesen.
    expect($schnell->y)->toBeLessThan($langsam->y)
        ->and($serie->showsTime())->toBeTrue();
});

it('zeichnet die Punkteachse in die andere Richtung', function () {
    start_wt($this->athlete, '2024-03-01', 7400, 600, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7000, 700, 'S4', 'SCM', 100);

    $zeilen = $this->service->profile($this->athlete, null, null, 'SCM', true)->byEvent->first();
    $serie = $this->chartService->series('100 m FREE', $zeilen, collect(), WpsChartService::METRIC_POINTS);

    expect($serie->points->last()->y)->toBeLessThan($serie->points->first()->y)
        ->and($serie->showsTime())->toBeFalse();
});

it('lässt in der Punkteansicht Zeilen ohne Punktzahl weg', function () {
    start_wt($this->athlete, '2024-03-01', 7400, 600, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7200, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-09-01', 7000, 700, 'S4', 'SCM', 100);

    $zeilen = $this->service->profile($this->athlete, null, null, 'SCM', true)->byEvent->first();

    $nachZeit = $this->chartService->series('100 m FREE', $zeilen, collect(), WpsChartService::METRIC_TIME);
    $nachPunkten = $this->chartService->series('100 m FREE', $zeilen, collect(), WpsChartService::METRIC_POINTS);

    expect($nachZeit->points)->toHaveCount(3)
        ->and($nachPunkten->points)->toHaveCount(2);
});

it('beschriftet die Zeitachse mit Zeiten', function () {
    start_wt($this->athlete, '2024-03-01', 7400, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7000, null, 'S4', 'SCM', 100);

    $zeilen = $this->service->profile($this->athlete, null, null, 'SCM', true)->byEvent->first();
    $serie = $this->chartService->series('100 m FREE', $zeilen, collect(), WpsChartService::METRIC_TIME);

    expect($serie->gridLines[0]['label'])->toContain(':')
        ->and($serie->axisLabel())->toBe('Zeit');
});

it('spreizt eine kleine Zeitschwankung nicht über die volle Höhe', function () {
    start_wt($this->athlete, '2024-03-01', 7000, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7020, null, 'S4', 'SCM', 100);

    $zeilen = $this->service->profile($this->athlete, null, null, 'SCM', true)->byEvent->first();
    $serie = $this->chartService->series('100 m FREE', $zeilen, collect(), WpsChartService::METRIC_TIME);

    // Über eine Saison schwankt eine 100-m-Zeit häufig nur um wenige Zehntel.
    expect($serie->maxValue - $serie->minValue)->toBeGreaterThanOrEqual(200);
});

// ── Gliederung ───────────────────────────────────────────────────────────────

it('sortiert die Bewerbe nach der Zahl der Starts', function () {
    start_wt($this->athlete, '2024-03-01', 7400, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-06-01', 7200, null, 'S4', 'SCM', 100);
    start_wt($this->athlete, '2024-09-01', 15000, null, 'S4', 'SCM', 200);

    $bewerbe = $this->service->profile($this->athlete, null, null, 'SCM', true)->byEvent->keys()->all();

    // Der Bewerb, in dem jemand am häufigsten antritt, ist sein Hauptbewerb — nach Punkten zu
    // sortieren ginge nicht, weil die meisten Zeilen keine haben.
    expect($bewerbe[0])->toContain('100 m');
});

it('schließt Staffeln aus', function () {
    $einzel = start_wt($this->athlete, '2024-03-01', 7400, null, 'S4', 'SCM', 100);
    $staffel = start_wt($this->athlete, '2024-06-01', 25000, null, 'S4', 'SCM', 400);
    $staffel->swimEvent->update(['relay_count' => 4]);

    $profil = $this->service->profile($this->athlete, null, null, 'SCM', true);

    // Eine Staffelzeit sagt über die Entwicklung eines Einzelnen nichts aus.
    expect($profil->entryCount())->toBe(1)
        ->and($profil->byEvent->first()->sole()->resultId)->toBe($einzel->getKey());
});

it('bezieht außer Konkurrenz geschwommene Ergebnisse ein', function () {
    start_wt($this->athlete, '2024-03-01', 7400, null, 'S4', 'SCM', 100);
    $exh = start_wt($this->athlete, '2024-06-01', 7000, null, 'S4', 'SCM', 100);
    $exh->update(['status' => 'EXH']);

    // Anders als in einer Rangliste: Für die Entwicklung eines Athleten ist eine außer
    // Konkurrenz geschwommene Zeit eine Auskunft wie jede andere.
    expect($this->service->profile($this->athlete, null, null, 'SCM', true)->entryCount())->toBe(2);
});

it('schließt Ergebnisse ohne wertbaren Status aus', function () {
    start_wt($this->athlete, '2024-03-01', 7400, null, 'S4', 'SCM', 100);
    $dsq = start_wt($this->athlete, '2024-06-01', 7000, null, 'S4', 'SCM', 100);
    $dsq->update(['status' => 'DSQ']);

    expect($this->service->profile($this->athlete, null, null, 'SCM', true)->entryCount())->toBe(1);
});

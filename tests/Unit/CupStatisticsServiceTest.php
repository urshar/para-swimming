<?php

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupOverallResult;
use App\Models\Nation;
use App\Models\SportClassGroup;
use App\Services\CupStatisticsService;
use App\Services\GroupResolverService;
use App\Services\OverallRankingService;
use App\Support\ReportConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('statistik-p10');

// ── Helpers ──────────────────────────────────────────────────────────────────

function cup10_service(): CupStatisticsService
{
    return new CupStatisticsService(new OverallRankingService(new GroupResolverService));
}

function cup10_config(int $year): ReportConfiguration
{
    return ReportConfiguration::forYear($year);
}

function cup10_nation(): Nation
{
    return Nation::firstOrCreate(
        ['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]
    );
}

function cup10_club(): Club
{
    return Club::create(['name' => 'Club '.uniqid(), 'nation_id' => cup10_nation()->id]);
}

function cup10_athlete(array $attrs = []): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => 'Max',
        'last_name' => 'Muster',
        'gender' => 'M',
        'nation_id' => cup10_nation()->id,
        'is_active' => true,
    ], $attrs));
}

function cup10_cup(int $year): Cup
{
    return Cup::create([
        'year' => $year,
        'name' => "ÖBSV Cup $year",
        'base_time_version_id' => BaseTimeVersion::create([
            'label' => "V$year", 'valid_from' => "$year-01-01",
        ])->id,
        'rounds_count' => 1,
        'best_of_count' => 3,
        'top_group_points_threshold' => 450,
    ]);
}

function cup10_group(string $code, int $sortOrder): SportClassGroup
{
    return SportClassGroup::create([
        'code' => $code,
        'name_de' => "Gruppe $code",
        'sort_order' => $sortOrder,
        'is_active' => true,
    ]);
}

function cup10_ageGroup(string $code, int $sortOrder): AgeGroup
{
    return AgeGroup::create([
        'code' => $code,
        'name_de' => $code === 'JUGEND' ? 'Jugend' : 'Offen',
        'min_age' => $code === 'JUGEND' ? null : 19,
        'max_age' => $code === 'JUGEND' ? 18 : null,
        'sort_order' => $sortOrder,
    ]);
}

/** Legt eine Zeile der bestehenden Gesamtwertung an (Snapshot, wird nur gelesen). */
function cup10_overallResult(
    Cup $cup,
    SportClassGroup $group,
    string $gender,
    ?AgeGroup $ageGroup = null,
    int $points = 1000,
): CupOverallResult {
    return CupOverallResult::create([
        'cup_id' => $cup->id,
        'athlete_id' => cup10_athlete(['gender' => $gender])->id,
        'club_id' => cup10_club()->id,
        'sport_class_group_id' => $group->id,
        'gender' => $gender,
        'age_group_id' => $ageGroup?->id,
        'total_points' => $points,
        'rounds_counted' => 1,
        'counted_meet_ids' => [],
        'calculated_at' => now(),
    ]);
}

// ── Cup-Zuordnung über das Berichtsjahr ──────────────────────────────────────

it('findet den Cup des Berichtsjahres', function () {
    $cup = cup10_cup(2026);
    cup10_cup(2025);

    expect(cup10_service()->cupForYear(cup10_config(2026))?->id)->toBe($cup->id);
});

it('liefert null, wenn für das Berichtsjahr kein Cup existiert', function () {
    cup10_cup(2025);

    expect(cup10_service()->cupForYear(cup10_config(2026)))->toBeNull();
});

it('liefert eine leere Collection, wenn für das Berichtsjahr kein Cup existiert', function () {
    expect(cup10_service()->overallRankingForConfiguration(cup10_config(2026)))->toBeEmpty();
});

// ── Wertungskategorien ───────────────────────────────────────────────────────

it('leitet die Wertungskategorien aus der bestehenden Gesamtwertung ab', function () {
    $cup = cup10_cup(2026);
    $pi = cup10_group('PI', 1);
    $jugend = cup10_ageGroup('JUGEND', 1);
    $offen = cup10_ageGroup('OFFEN', 2);

    cup10_overallResult($cup, $pi, 'F', $jugend);
    cup10_overallResult($cup, $pi, 'M', $jugend);
    cup10_overallResult($cup, $pi, 'M', $offen);

    $rows = cup10_service()->overallRanking($cup);

    expect($rows)->toHaveCount(3)
        ->and($rows->map(fn (array $r): string => $r['age_group_code'].'/'.$r['gender'])->all())
        ->toBe(['JUGEND/F', 'JUGEND/M', 'OFFEN/M']);
});

it('führt nur Kategorien auf, für die tatsächlich Wertungen vorliegen', function () {
    $cup = cup10_cup(2026);
    $pi = cup10_group('PI', 1);
    cup10_group('VI', 2); // ohne Wertungen

    cup10_overallResult($cup, $pi, 'M');

    $rows = cup10_service()->overallRanking($cup);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['group_code'])->toBe('PI');
});

it('fasst Damen und Herren zu einer Kategorie zusammen, wenn die Gruppe gemeinsam gewertet wird', function () {
    $cup = cup10_cup(2026);
    $pi = cup10_group('PI', 1);
    $cup->groupSettings()->create(['sport_class_group_id' => $pi->id, 'gender_combined' => true]);

    cup10_overallResult($cup, $pi, 'M');
    cup10_overallResult($cup, $pi, 'F');

    $rows = cup10_service()->overallRanking($cup->fresh());

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['gender'])->toBeNull()
        ->and($rows->first()['athletes'])->toBe(2);
});

it('trennt Damen und Herren, wenn keine gemeinsame Wertung konfiguriert ist', function () {
    $cup = cup10_cup(2026);
    $pi = cup10_group('PI', 1);

    cup10_overallResult($cup, $pi, 'M');
    cup10_overallResult($cup, $pi, 'F');

    $rows = cup10_service()->overallRanking($cup);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('gender')->all())->toBe(['F', 'M']);
});

it('sortiert die Kategorien nach der sort_order der Sportklassengruppe', function () {
    $cup = cup10_cup(2026);
    $vi = cup10_group('VI', 2);
    $pi = cup10_group('PI', 1);
    $ii = cup10_group('II', 3);

    foreach ([$vi, $pi, $ii] as $group) {
        cup10_overallResult($cup, $group, 'M');
    }

    expect(cup10_service()->overallRanking($cup)->pluck('group_code')->all())
        ->toBe(['PI', 'VI', 'II']);
});

it('stellt Kategorien ohne Altersgruppe ans Ende der jeweiligen Gruppe', function () {
    $cup = cup10_cup(2026);
    $pi = cup10_group('PI', 1);
    $jugend = cup10_ageGroup('JUGEND', 1);

    cup10_overallResult($cup, $pi, 'M');
    cup10_overallResult($cup, $pi, 'M', $jugend);

    expect(cup10_service()->overallRanking($cup)->pluck('age_group_code')->all())
        ->toBe(['JUGEND', null]);
});

// ── Inhalt der Kategorien ────────────────────────────────────────────────────

it('liefert die gereihte Gesamtwertung je Kategorie aus dem bestehenden Service', function () {
    $cup = cup10_cup(2026);
    $pi = cup10_group('PI', 1);

    cup10_overallResult($cup, $pi, 'M', points: 800);
    cup10_overallResult($cup, $pi, 'M', points: 1200);
    cup10_overallResult($cup, $pi, 'M', points: 950);

    $row = cup10_service()->overallRanking($cup)->first();

    expect($row['athletes'])->toBe(3)
        ->and($row['results']->pluck('total_points')->all())->toBe([1200, 950, 800])
        ->and($row['results']->first()->rank)->toBe(1);
});

it('trennt die Wertungen verschiedener Cup-Jahre', function () {
    $pi = cup10_group('PI', 1);
    $cup2026 = cup10_cup(2026);
    $cup2025 = cup10_cup(2025);

    cup10_overallResult($cup2026, $pi, 'M');
    cup10_overallResult($cup2025, $pi, 'M');
    cup10_overallResult($cup2025, $pi, 'M');

    expect(cup10_service()->overallRanking($cup2026)->first()['athletes'])->toBe(1)
        ->and(cup10_service()->overallRanking($cup2025)->first()['athletes'])->toBe(2);
});

it('wählt über die Konfiguration die Wertung des richtigen Jahres', function () {
    $pi = cup10_group('PI', 1);
    $cup2026 = cup10_cup(2026);
    $cup2025 = cup10_cup(2025);

    cup10_overallResult($cup2026, $pi, 'M');
    cup10_overallResult($cup2025, $pi, 'F');

    $rows = cup10_service()->overallRankingForConfiguration(cup10_config(2026));

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['gender'])->toBe('M');
});

it('enthält pro Kategorie die erwarteten Schlüssel', function () {
    $cup = cup10_cup(2026);
    cup10_overallResult($cup, cup10_group('PI', 1), 'M', cup10_ageGroup('JUGEND', 1));

    expect(cup10_service()->overallRanking($cup)->first())->toHaveKeys([
        'gender', 'group_id', 'group_code', 'group_name',
        'age_group_id', 'age_group_code', 'age_group_name',
        'athletes', 'results',
    ]);
});

it('liefert eine leere Collection, wenn der Cup noch keine Gesamtwertung hat', function () {
    expect(cup10_service()->overallRanking(cup10_cup(2026)))->toBeEmpty();
});

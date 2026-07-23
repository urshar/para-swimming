<?php

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupOverallResult;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Services\CupStatisticsService;
use App\Services\GroupResolverService;
use App\Services\OverallRankingService;
use App\Services\ParticipationStatisticsService;
use App\Services\RecordStatisticsService;
use App\Services\StatisticsService;
use App\Support\ReportConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('statistik-p11');

// ── Helpers ──────────────────────────────────────────────────────────────────

function stat11_service(): StatisticsService
{
    return new StatisticsService(
        new ParticipationStatisticsService(new GroupResolverService),
        new RecordStatisticsService,
        new CupStatisticsService(new OverallRankingService(new GroupResolverService)),
    );
}

function stat11_config(array $overrides = []): ReportConfiguration
{
    return ReportConfiguration::fromArray(array_merge(['year' => 2024], $overrides));
}

function stat11_nation(): Nation
{
    return Nation::firstOrCreate(
        ['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]
    );
}

function stat11_strokeType(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        [
            'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
            'category' => 'standard', 'is_active' => true,
        ]
    );
}

/**
 * Legt einen kleinen, vollständigen Datenbestand für 2024 an: eine
 * Veranstaltung mit zwei Starts, einen Rekord und eine Cup-Gesamtwertung.
 *
 * @return array{athlete: Athlete, club: Club, meet: Meet, cup: Cup}
 */
function stat11_seed(): array
{
    $nation = stat11_nation();
    $club = Club::create(['name' => 'Testclub', 'nation_id' => $nation->id]);

    $athlete = Athlete::create([
        'first_name' => 'Anna', 'last_name' => 'Muster', 'gender' => 'F',
        'birth_date' => '2006-05-10', 'nation_id' => $nation->id, 'is_active' => true,
    ]);

    $group = SportClassGroup::create([
        'code' => 'PI', 'name_de' => 'Körperliche Behinderung', 'sort_order' => 1, 'is_active' => true,
    ]);
    SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);

    AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'sort_order' => 1]);
    AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'sort_order' => 2]);

    $meet = Meet::create([
        'name' => 'Testmeet', 'nation_id' => $nation->id,
        'course' => 'LCM', 'start_date' => '2024-06-01',
    ]);

    // Zwei gültige Starts desselben Athleten in zwei Bewerben.
    foreach ([100, 200] as $distance) {
        $event = SwimEvent::create([
            'meet_id' => $meet->id, 'stroke_type_id' => stat11_strokeType()->id,
            'distance' => $distance, 'gender' => 'A', 'relay_count' => 1,
        ]);

        Result::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id, 'club_id' => $club->id,
            'sport_class' => 'S9', 'swim_time' => 6000,
        ]);
    }

    SwimRecord::create([
        'stroke_type_id' => stat11_strokeType()->id, 'athlete_id' => $athlete->id,
        'record_type' => 'AUT', 'sport_class' => 'S9', 'gender' => 'F',
        'distance' => 100, 'swim_time' => 6000, 'set_date' => '2024-06-01',
    ]);

    $cup = Cup::create([
        'year' => 2024, 'name' => 'ÖBSV Cup 2024',
        'base_time_version_id' => BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2024-01-01'])->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);

    CupOverallResult::create([
        'cup_id' => $cup->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class_group_id' => $group->id, 'gender' => 'F', 'age_group_id' => null,
        'total_points' => 1000, 'rounds_counted' => 1, 'counted_meet_ids' => [], 'calculated_at' => now(),
    ]);

    return ['athlete' => $athlete, 'club' => $club, 'meet' => $meet, 'cup' => $cup];
}

// ── Abschnittsauswahl ────────────────────────────────────────────────────────

it('liefert alle Abschnitte in der kanonischen Reihenfolge', function () {
    stat11_seed();

    expect(array_keys(stat11_service()->generate(stat11_config())))
        ->toBe(ReportConfiguration::SECTION_KEYS);
});

it('lässt deaktivierte Abschnitte vollständig weg', function () {
    stat11_seed();

    $result = stat11_service()->generate(stat11_config([
        'sections' => ['records' => false, 'cup' => false],
    ]));

    expect($result)->not->toHaveKey('records')
        ->and($result)->not->toHaveKey('cup')
        ->and($result)->toHaveKey('overview');
});

it('liefert nur den einen aktivierten Abschnitt', function () {
    stat11_seed();

    $sections = array_fill_keys(ReportConfiguration::SECTION_KEYS, false);
    $sections['overview'] = true;

    expect(array_keys(stat11_service()->generate(stat11_config(['sections' => $sections]))))
        ->toBe(['overview']);
});

it('liefert ein leeres Ergebnis, wenn kein Abschnitt aktiviert ist', function () {
    stat11_seed();

    $sections = array_fill_keys(ReportConfiguration::SECTION_KEYS, false);

    expect(stat11_service()->generate(stat11_config(['sections' => $sections])))->toBe([]);
});

// ── Inhalt der Abschnitte ────────────────────────────────────────────────────

it('fasst im Überblick Basiskennzahlen, Schwellenwert und Statusverteilung zusammen', function () {
    stat11_seed();

    $overview = stat11_service()->generate(stat11_config())['overview'];

    expect($overview)->toHaveKeys([
        'meets', 'participants', 'clubs', 'foreign_clubs', 'starts', 'participations',
        'min_participations', 'athletes_with_min_participations', 'status_breakdown',
    ])
        ->and($overview['meets'])->toBe(1)
        ->and($overview['participants'])->toBe(1)
        ->and($overview['starts'])->toBe(2)
        ->and($overview['participations'])->toBe(1)
        ->and($overview['min_participations'])->toBe(2)
        ->and($overview['status_breakdown']['regular'])->toBe(2);
});

it('übernimmt einen konfigurierten Teilnahme-Schwellenwert in den Überblick', function () {
    stat11_seed();

    $overview = stat11_service()->generate(stat11_config(['min_participations' => 5]))['overview'];

    expect($overview['min_participations'])->toBe(5)
        ->and($overview['athletes_with_min_participations'])->toBe(0);
});

it('liefert die Veranstaltungsstatistik als eigenen Abschnitt', function () {
    $data = stat11_seed();

    $meets = stat11_service()->generate(stat11_config())['meets'];

    expect($meets)->toHaveCount(1)
        ->and($meets->first()['meet_id'])->toBe($data['meet']->id)
        ->and($meets->first()['starts'])->toBe(2);
});

it('gliedert den Teilnehmerabschnitt nach Altersgruppe und Geschlecht', function () {
    stat11_seed();

    $participants = stat11_service()->generate(stat11_config())['participants'];

    expect($participants)->toHaveKeys(['by_age_group', 'by_gender', 'by_age_group_and_gender'])
        ->and($participants['by_age_group']->first()['age_group_code'])->toBe('JUGEND')
        ->and($participants['by_gender']->first()['gender'])->toBe('F')
        ->and($participants['by_age_group_and_gender']->first()['gender'])->toBe('F');
});

it('liefert Vereine, Sportler und Nationen als gereihte Listen', function () {
    $data = stat11_seed();
    $result = stat11_service()->generate(stat11_config());

    expect($result['clubs']->first()['club_id'])->toBe($data['club']->id)
        ->and($result['athletes']->first()['athlete_id'])->toBe($data['athlete']->id)
        ->and($result['athletes']->first()['participations'])->toBe(1)
        ->and($result['nations']->first()['nation'])->toBe('AUT');
});

it('gliedert den Sportklassenabschnitt nach Klasse und Behinderungsgruppe', function () {
    stat11_seed();

    $sportClasses = stat11_service()->generate(stat11_config())['sport_classes'];

    expect($sportClasses)->toHaveKeys(['by_sport_class', 'by_disability_group'])
        ->and($sportClasses['by_sport_class']->first()['sport_class'])->toBe('S9')
        ->and($sportClasses['by_disability_group']->first()['group_code'])->toBe('PI');
});

it('gliedert den Rekordabschnitt in Überblick, Athleten und Rekordarten', function () {
    stat11_seed();

    $records = stat11_service()->generate(stat11_config())['records'];

    expect($records)->toHaveKeys(['overview', 'by_athlete', 'by_record_type'])
        ->and($records['overview']['total'])->toBe(1)
        ->and($records['overview']['austrian'])->toBe(1)
        ->and($records['by_athlete']->first()['records'])->toBe(1)
        ->and($records['by_record_type']->first()['record_type'])->toBe('AUT');
});

it('liefert die Cup-Gesamtwertung des Berichtsjahres', function () {
    stat11_seed();

    $cup = stat11_service()->generate(stat11_config())['cup'];

    expect($cup)->toHaveCount(1)
        ->and($cup->first()['group_code'])->toBe('PI')
        ->and($cup->first()['athletes'])->toBe(1);
});

it('liefert einen leeren Cup-Abschnitt, wenn es für das Jahr keinen Cup gibt', function () {
    stat11_seed();

    expect(stat11_service()->generate(stat11_config(['year' => 2023]))['cup'])->toBeEmpty();
});

// ── Zeitraum ─────────────────────────────────────────────────────────────────

it('berücksichtigt den Berichtszeitraum in allen Abschnitten', function () {
    stat11_seed();

    $result = stat11_service()->generate(stat11_config(['year' => 2023]));

    expect($result['overview']['starts'])->toBe(0)
        ->and($result['meets'])->toBeEmpty()
        ->and($result['clubs'])->toBeEmpty()
        ->and($result['records']['overview']['total'])->toBe(0);
});

it('berücksichtigt eine Einschränkung auf ausgewählte Veranstaltungen', function () {
    stat11_seed();

    $other = Meet::create([
        'name' => 'Anderes Meet', 'nation_id' => stat11_nation()->id,
        'course' => 'LCM', 'start_date' => '2024-09-01',
    ]);

    $result = stat11_service()->generate(stat11_config(['meet_ids' => [$other->id]]));

    expect($result['overview']['starts'])->toBe(0)
        ->and($result['meets'])->toBeEmpty();
});

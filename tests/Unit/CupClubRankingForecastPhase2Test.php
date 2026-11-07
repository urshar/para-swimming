<?php

use App\Models\Athlete;
use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\ClubRankingEntryProjectionService;
use App\Services\WorldAquaticsPointsService;
use App\Support\UnratedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/*
 * Prognose der Vereinswertung, Phase 2 (docs/specs/cup-club-ranking-forecast.md §3–§5):
 * Ableitung von Starts und Tageswertungs-Zeilen aus den Meldungen eines Prognose-Meets.
 *
 * Basiswerte: 100 m Freistil LCM Herren, S9 und S10 je 100,00 s — eine Meldezeit von 100,00 s
 * ergibt 1000 Punkte, 125,00 s ergeben 512 Punkte. S9 gehört zur aktiven Gruppe PI, S10 zu
 * keiner Gruppe; S5 hat keinen Basiswert.
 */
uses(TestCase::class, RefreshDatabase::class)->group('cup-club-ranking-forecast-p2');

// ── Helpers ──────────────────────────────────────────────────────────────────

function service_fc2(): ClubRankingEntryProjectionService
{
    return app(ClubRankingEntryProjectionService::class);
}

function nextSeq_fc2(): int
{
    static $n = 0;

    return ++$n;
}

function nation_fc2(string $code): Nation
{
    return Nation::firstOrCreate(['code' => $code], ['name_de' => $code, 'name_en' => $code, 'is_active' => true]);
}

function stroke_fc2(string $code): StrokeType
{
    return StrokeType::firstOrCreate(['code' => $code],
        ['lenex_code' => $code, 'name_de' => $code, 'name_en' => $code, 'category' => 'standard', 'is_active' => true]);
}

function club_fc2(string $name): Club
{
    return Club::create(['name' => $name, 'nation_id' => nation_fc2('AUT')->id]);
}

function athlete_fc2(?Club $club): Athlete
{
    $seq = nextSeq_fc2();

    return Athlete::create(['first_name' => "Vorname$seq", 'last_name' => "Nachname$seq", 'gender' => 'M',
        'nation_id' => nation_fc2('AUT')->id, 'club_id' => $club?->id, 'is_active' => true]);
}

function cup_fc2(): Cup
{
    $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
    SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);

    $version = BaseTimeVersion::create(['label' => 'V2026', 'valid_from' => '2026-01-01']);
    $discipline = BaseTimeDiscipline::create(['stroke_type_id' => stroke_fc2('FREE')->id, 'distance' => 100,
        'relay_count' => 1, 'code' => 'FREE_100']);
    $category = BaseTimeCategory::create(['course' => 'LCM', 'gender' => 'M', 'code' => 'LCM_M', 'label' => 'LCM Männer']);

    foreach (['S9', 'S10'] as $code) {
        $sportClass = BaseTimeSportClass::create(['code' => $code, 'sort_order' => 1]);
        BaseTime::create(['base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
            'base_time_discipline_id' => $discipline->id, 'base_time_sport_class_id' => $sportClass->id,
            'value_centiseconds' => 10000]);
    }

    BaseTimeSportClass::create(['code' => 'S5', 'sort_order' => 2]);

    return Cup::create(['year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 2, 'best_of_count' => 3]);
}

function meet_fc2(?Cup $cup): Meet
{
    return Meet::create(['name' => 'Meet '.nextSeq_fc2(), 'nation_id' => nation_fc2('AUT')->id, 'course' => 'LCM',
        'start_date' => '2026-11-20', 'cup_id' => $cup?->id]);
}

function event_fc2(Meet $meet, int $distance = 100, string $stroke = 'FREE', int $relayCount = 1, string $round = 'TIM'): SwimEvent
{
    return SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_fc2($stroke)->id,
        'distance' => $distance, 'relay_count' => $relayCount, 'round' => $round, 'gender' => 'M']);
}

function entry_fc2(
    Meet $meet,
    SwimEvent $event,
    Athlete $athlete,
    Club $club,
    ?int $entryTime,
    ?string $sportClass,
    ?string $status,
    ?string $entryCourse
): Entry {
    return Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'entry_time' => $entryTime, 'entry_time_code' => $entryTime === null ? 'NT' : null,
        'sport_class' => $sportClass, 'status' => $status, 'entry_course' => $entryCourse]);
}

/** Reguläre Meldung über 100 m Freistil S9 in einem eigenen Bewerb. */
function simpleEntry_fc2(Meet $meet, Athlete $athlete, Club $club, ?int $entryTime): Entry
{
    return entry_fc2($meet, event_fc2($meet), $athlete, $club, $entryTime, 'S9', null, 'LCM');
}

/**
 * Grund, weshalb eine Meldung nicht bewertbar ist (oder null, wenn sie bewertet wurde).
 *
 * @param  Collection<int, UnratedEntry>  $unrated
 */
function reasonFor_fc2(Entry $entry, Collection $unrated): ?string
{
    return $unrated->firstWhere('entryId', $entry->id)?->reason;
}

// ── Prognose-Meets ───────────────────────────────────────────────────────────

it('erkennt als Prognose-Meet nur Cup-Meets mit Meldungen und ohne Ergebnisse', function () {
    $cup = cup_fc2();
    $club = club_fc2('Verein A');
    $athlete = athlete_fc2($club);

    $forecast = meet_fc2($cup);
    simpleEntry_fc2($forecast, $athlete, $club, 10000);

    $withResults = meet_fc2($cup);
    simpleEntry_fc2($withResults, $athlete, $club, 10000);
    Result::create(['meet_id' => $withResults->id, 'swim_event_id' => event_fc2($withResults)->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'swim_time' => 10000, 'sport_class' => 'S9']);

    meet_fc2($cup);

    $foreignCupMeet = meet_fc2(null);
    simpleEntry_fc2($foreignCupMeet, $athlete, $club, 10000);

    expect(service_fc2()->forecastMeets($cup)->pluck('id')->all())->toBe([$forecast->id]);
});

// ── Starts ───────────────────────────────────────────────────────────────────

it('zählt reguläre Meldungen inklusive NT als Start, aber keine abgemeldeten, EXH- oder Staffelmeldungen', function () {
    $cup = cup_fc2();
    $meet = meet_fc2($cup);
    $club = club_fc2('Verein A');
    $athlete = athlete_fc2($club);

    simpleEntry_fc2($meet, $athlete, $club, 10000);
    entry_fc2($meet, event_fc2($meet, 50), $athlete, $club, null, 'S9', null, null);

    foreach (['EXH', 'RJC', 'SICK', 'WDR'] as $index => $status) {
        entry_fc2($meet, event_fc2($meet, 200 + $index), $athlete, $club, 10000, 'S9', $status, 'LCM');
    }

    entry_fc2($meet, event_fc2($meet, 50, relayCount: 4), $athlete, $club, 10000, 'S9', null, 'LCM');

    $starts = service_fc2()->projectStarts($meet);

    expect($starts)->toHaveCount(2)
        ->and($starts->pluck('meet_id')->unique()->all())->toBe([$meet->id]);
});

it('fasst Meldungen derselben Disziplin (Vorlauf und Finale) zu einem Start zusammen', function () {
    $cup = cup_fc2();
    $meet = meet_fc2($cup);
    $club = club_fc2('Verein A');
    $athlete = athlete_fc2($club);

    entry_fc2($meet, event_fc2($meet, round: 'PRE'), $athlete, $club, 10000, 'S9', null, 'LCM');
    entry_fc2($meet, event_fc2($meet, round: 'FIN'), $athlete, $club, 10000, 'S9', null, 'LCM');
    entry_fc2($meet, event_fc2($meet, stroke: 'BACK'), $athlete, $club, 10000, 'S9', null, 'LCM');

    expect(service_fc2()->projectStarts($meet))->toHaveCount(2);
});

it('ordnet prognostizierte Starts dem meldenden Verein zu, nicht dem aktuellen Verein des Athleten', function () {
    $cup = cup_fc2();
    $meet = meet_fc2($cup);
    $currentClub = club_fc2('Aktueller Verein');
    $enteringClub = club_fc2('Meldender Verein');
    $athlete = athlete_fc2($currentClub);

    simpleEntry_fc2($meet, $athlete, $enteringClub, 10000);

    expect(service_fc2()->projectStarts($meet)->all())
        ->toBe([['club_id' => $enteringClub->id, 'athlete_id' => $athlete->id, 'meet_id' => $meet->id]]);
});

// ── Punkte ───────────────────────────────────────────────────────────────────

it('berechnet die Punkte einer Meldung genau wie der WorldAquaticsPointsService für ein Ergebnis', function () {
    $cup = cup_fc2();
    $meet = meet_fc2($cup);
    $club = club_fc2('Verein A');
    $athlete = athlete_fc2($club);
    $event = event_fc2($meet);

    entry_fc2($meet, $event, $athlete, $club, 12500, 'S9', null, 'LCM');

    $comparison = Result::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'swim_time' => 12500, 'sport_class' => 'S9']);
    $expected = app(WorldAquaticsPointsService::class)->calculatePoints($comparison, $meet, $cup->baseTimeVersion);
    $comparison->delete();

    $rows = service_fc2()->projectDailyResults($meet, $cup)->rows;

    expect($expected)->toBe(512)
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->points)->toBe($expected)
        ->and($rows->first()->athlete_id)->toBe($athlete->id)
        ->and($rows->first()->club_id)->toBe($club->id)
        ->and($rows->first()->meet_id)->toBe($meet->id);
});

it('wertet je Athlet nur die punktbeste Meldung', function () {
    $cup = cup_fc2();
    $meet = meet_fc2($cup);
    $club = club_fc2('Verein A');
    $athlete = athlete_fc2($club);

    simpleEntry_fc2($meet, $athlete, $club, 12500);
    $best = simpleEntry_fc2($meet, $athlete, $club, 10000);

    $rows = service_fc2()->projectDailyResults($meet, $cup)->rows;

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->points)->toBe(1000)
        ->and($rows->first()->entry_id)->toBe($best->id);
});

it('führt Meldungen ohne ermittelbare Punkte mit Grund als nicht bewertbar', function () {
    $cup = cup_fc2();
    $meet = meet_fc2($cup);
    $club = club_fc2('Verein A');

    $noTime = simpleEntry_fc2($meet, athlete_fc2($club), $club, null);
    $noClass = entry_fc2($meet, event_fc2($meet), athlete_fc2($club), $club, 10000, null, null, 'LCM');
    $noBaseTime = entry_fc2($meet, event_fc2($meet), athlete_fc2($club), $club, 10000, 'S5', null, 'LCM');
    $noGroup = entry_fc2($meet, event_fc2($meet), athlete_fc2($club), $club, 10000, 'S10', null, 'LCM');

    $projection = service_fc2()->projectDailyResults($meet, $cup);

    expect($projection->rows)->toBeEmpty()
        ->and($projection->unrated)->toHaveCount(4)
        ->and(reasonFor_fc2($noTime, $projection->unrated))->toBe('keine Meldezeit (NT)')
        ->and(reasonFor_fc2($noClass, $projection->unrated))->toBe('keine Sportklasse in der Meldung')
        ->and(reasonFor_fc2($noBaseTime, $projection->unrated))->toContain('kein Basiswert-Eintrag')
        ->and(reasonFor_fc2($noGroup, $projection->unrated))
        ->toBe('Sportklasse keiner aktiven Sportklassengruppe zugeordnet');
});

it('ignoriert abgemeldete und EXH-Meldungen auch in der Punkte-Prognose', function () {
    $cup = cup_fc2();
    $meet = meet_fc2($cup);
    $club = club_fc2('Verein A');

    foreach (['EXH', 'RJC', 'SICK', 'WDR'] as $status) {
        entry_fc2($meet, event_fc2($meet), athlete_fc2($club), $club, 10000, 'S9', $status, 'LCM');
    }

    $projection = service_fc2()->projectDailyResults($meet, $cup);

    expect($projection->rows)->toBeEmpty()
        ->and($projection->unrated)->toBeEmpty();
});

it('wertet Meldezeiten aus einer anderen Bahnlänge ohne Umrechnung und kennzeichnet sie', function () {
    $cup = cup_fc2();
    $meet = meet_fc2($cup);
    $club = club_fc2('Verein A');
    $shortCourse = athlete_fc2($club);
    $longCourse = athlete_fc2($club);

    entry_fc2($meet, event_fc2($meet), $shortCourse, $club, 10000, 'S9', null, 'SCM');
    entry_fc2($meet, event_fc2($meet), $longCourse, $club, 10000, 'S9', null, 'LCM');

    $rows = service_fc2()->projectDailyResults($meet, $cup)->rows->keyBy('athlete_id');

    expect($rows->get($shortCourse->id)->points)->toBe(1000)
        ->and($rows->get($shortCourse->id)->off_course)->toBeTrue()
        ->and($rows->get($longCourse->id)->off_course)->toBeFalse();
});

it('ordnet prognostizierte Punkte dem meldenden Verein zu', function () {
    $cup = cup_fc2();
    $meet = meet_fc2($cup);
    $enteringClub = club_fc2('Meldender Verein');
    $athlete = athlete_fc2(club_fc2('Aktueller Verein'));

    simpleEntry_fc2($meet, $athlete, $enteringClub, 10000);

    expect(service_fc2()->projectDailyResults($meet, $cup)->rows->first()->club_id)->toBe($enteringClub->id);
});

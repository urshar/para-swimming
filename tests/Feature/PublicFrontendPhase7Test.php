<?php

use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Qualification;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\AnnualBestService;
use App\Services\OverallRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('public-p7');

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeNation_pfr7(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], [
        'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
    ]);
}

function makeClub_pfr7(string $name = 'Testclub', ?string $shortName = null): Club
{
    static $i = 0;
    $i++;

    return Club::create([
        'name' => $name, 'short_name' => $shortName, 'code' => 'C'.$i,
        'nation_id' => makeNation_pfr7()->id,
    ]);
}

function makeAthlete_pfr7(
    Club $club,
    string $firstName = 'Max',
    string $lastName = 'Mustermann',
    string $gender = 'M'
): Athlete {
    return Athlete::create([
        'first_name' => $firstName, 'last_name' => $lastName, 'gender' => $gender,
        'nation_id' => makeNation_pfr7()->id, 'club_id' => $club->id, 'is_active' => true,
    ]);
}

function makeStrokeType_pfr7(string $lenexCode = 'FREE'): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $lenexCode], [
        'name_de' => $lenexCode, 'name_en' => $lenexCode, 'code' => strtolower($lenexCode), 'is_active' => true,
    ]);
}

function makeMeet_pfr7(string $startDate, ?int $cupId = null): Meet
{
    static $k = 0;
    $k++;

    return Meet::create([
        'name' => 'Meet '.$k, 'nation_id' => makeNation_pfr7()->id, 'course' => 'LCM',
        'start_date' => $startDate, 'cup_id' => $cupId,
    ]);
}

function makeSwimEvent_pfr7(Meet $meet, StrokeType $stroke, int $distance = 100, int $relayCount = 1): SwimEvent
{
    return SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => $distance,
        'relay_count' => $relayCount, 'gender' => 'M',
    ]);
}

function makeResult_pfr7(
    Meet $meet,
    SwimEvent $event,
    Athlete $athlete,
    Club $club,
    int $points,
    string $sportClass = 'S9',
    ?string $status = null,
): Result {
    static $l = 0;
    $l++;

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'swim_time' => 6000, 'sport_class' => $sportClass,
        'points' => $points, 'status' => $status, 'lane' => $l,
    ]);
}

function makeSportClassGroup_pfr7(string $code, int $sortOrder = 1): SportClassGroup
{
    return SportClassGroup::create([
        'code' => $code, 'name_de' => $code, 'sort_order' => $sortOrder, 'is_active' => true,
    ]);
}

function assignToGroup_pfr7(SportClassGroup $group, string $sportClass): void
{
    SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => $sportClass]);
}

function makeCup_pfr7(int $year): Cup
{
    static $v = 0;
    $v++;
    $version = BaseTimeVersion::create(['label' => 'V'.$v, 'valid_from' => '2021-01-01']);

    return Cup::create([
        'year' => $year, 'name' => "ÖBSV Cup $year", 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);
}

function makeDailyResult_pfr7(
    Cup $cup,
    Meet $meet,
    Athlete $athlete,
    Club $club,
    SportClassGroup $group,
    int $points
): CupDailyResult {
    $event = makeSwimEvent_pfr7($meet, makeStrokeType_pfr7());
    $result = makeResult_pfr7($meet, $event, $athlete, $club, $points);

    return CupDailyResult::create([
        'cup_id' => $cup->id, 'meet_id' => $meet->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'result_id' => $result->id, 'sport_class_group_id' => $group->id, 'gender' => $athlete->gender,
        'points' => $points, 'calculated_at' => now(),
    ]);
}

function makeQualifyingList_pfr7(int $year = 2026, bool $isActive = true): QualifyingTimeList
{
    return QualifyingTimeList::create([
        'year' => $year, 'is_active' => $isActive,
        'qualification_period_start' => "$year-01-01", 'qualification_period_end' => "$year-12-31",
    ]);
}

function makeQualification_pfr7(
    QualifyingTimeList $list,
    StrokeType $stroke,
    Athlete $athlete,
    Club $club,
    string $sportClass = 'S9',
    string $gender = 'M',
): Qualification {
    $qualifyingTime = QualifyingTime::create([
        'qualifying_time_list_id' => $list->id, 'stroke_type_id' => $stroke->id, 'distance' => 100,
        'gender' => $gender, 'sport_class' => $sportClass, 'value_centiseconds' => 7000,
        'source' => QualifyingTime::SOURCE_CALCULATED,
    ]);

    $meet = makeMeet_pfr7('2026-05-01');
    $event = makeSwimEvent_pfr7($meet, $stroke);
    $result = makeResult_pfr7($meet, $event, $athlete, $club, 500, $sportClass);

    return Qualification::create([
        'meet_id' => $meet->id, 'qualifying_time_list_id' => $list->id, 'qualifying_time_id' => $qualifyingTime->id,
        'athlete_id' => $athlete->id, 'result_id' => $result->id, 'club_id' => $club->id, 'sport_class' => $sportClass,
        'swim_time_centiseconds' => 6500, 'points' => 500, 'qualified_at' => now(),
    ]);
}

// ── AnnualBestService ────────────────────────────────────────────────────────

describe('AnnualBestService::forYear', function () {
    it('schließt EXH-Ergebnisse aus, auch wenn sie die höchste Punktzahl hätten', function () {
        $club = makeClub_pfr7();
        $athlete = makeAthlete_pfr7($club);
        $group = makeSportClassGroup_pfr7('PI');
        assignToGroup_pfr7($group, 'S9');
        $meet = makeMeet_pfr7('2026-05-01');
        $event = makeSwimEvent_pfr7($meet, makeStrokeType_pfr7());
        makeResult_pfr7($meet, $event, $athlete, $club, 999, status: 'EXH');
        makeResult_pfr7($meet, $event, $athlete, $club, 500);

        $buckets = app(AnnualBestService::class)->forYear(2026);

        expect($buckets->first()['results']->first()->points)->toBe(500);
    });

    it('schließt Staffeln aus (relay_count != 1)', function () {
        $club = makeClub_pfr7();
        $athlete = makeAthlete_pfr7($club);
        $group = makeSportClassGroup_pfr7('PI');
        assignToGroup_pfr7($group, 'S9');
        $meet = makeMeet_pfr7('2026-05-01');
        $relayEvent = makeSwimEvent_pfr7($meet, makeStrokeType_pfr7(), relayCount: 4);
        makeResult_pfr7($meet, $relayEvent, $athlete, $club, 999);

        $buckets = app(AnnualBestService::class)->forYear(2026);

        expect($buckets)->toBeEmpty();
    });

    it('zeigt genau eine Zeile je Person — das punktbeste Ergebnis über alle Bewerbe', function () {
        $club = makeClub_pfr7();
        $athlete = makeAthlete_pfr7($club);
        $group = makeSportClassGroup_pfr7('PI');
        assignToGroup_pfr7($group, 'S9');
        $meet = makeMeet_pfr7('2026-05-01');
        $event = makeSwimEvent_pfr7($meet, makeStrokeType_pfr7());
        makeResult_pfr7($meet, $event, $athlete, $club, 500);
        makeResult_pfr7($meet, $event, $athlete, $club, 700);
        makeResult_pfr7($meet, $event, $athlete, $club, 300);

        $buckets = app(AnnualBestService::class)->forYear(2026);

        expect($buckets)->toHaveCount(1)
            ->and($buckets->first()['results'])->toHaveCount(1)
            ->and($buckets->first()['results']->first()->points)->toBe(700);
    });

    it('berücksichtigt nur Ergebnisse innerhalb des Kalenderjahrs', function () {
        $club = makeClub_pfr7();
        $athlete = makeAthlete_pfr7($club);
        $group = makeSportClassGroup_pfr7('PI');
        assignToGroup_pfr7($group, 'S9');
        $meetLastYear = makeMeet_pfr7('2025-12-31');
        makeResult_pfr7($meetLastYear, makeSwimEvent_pfr7($meetLastYear, makeStrokeType_pfr7()), $athlete, $club, 900);

        $buckets = app(AnnualBestService::class)->forYear(2026);

        expect($buckets)->toBeEmpty();
    });

    it('gruppiert nach Geschlecht und Behinderungsgruppe über SportClassGroupMember', function () {
        $club = makeClub_pfr7();
        $piAthlete = makeAthlete_pfr7($club, 'Anna', 'PI', 'F');
        $viAthlete = makeAthlete_pfr7($club, 'Bea', 'VI', 'F');
        $pi = makeSportClassGroup_pfr7('PI');
        $vi = makeSportClassGroup_pfr7('VI', 2);
        assignToGroup_pfr7($pi, 'S9');
        assignToGroup_pfr7($vi, 'S12');
        $meet = makeMeet_pfr7('2026-05-01');
        $event = makeSwimEvent_pfr7($meet, makeStrokeType_pfr7());
        makeResult_pfr7($meet, $event, $piAthlete, $club, 500);
        makeResult_pfr7($meet, $event, $viAthlete, $club, 600, 'S12');

        $buckets = app(AnnualBestService::class)->forYear(2026);

        expect($buckets)->toHaveCount(2)
            ->and($buckets->firstWhere(fn ($b
            ) => $b['group']->code === 'PI')['results']->first()->athlete->first_name)->toBe('Anna')
            ->and($buckets->firstWhere(fn ($b
            ) => $b['group']->code === 'VI')['results']->first()->athlete->first_name)->toBe('Bea');
    });
});

// ── Public\AnnualBestController ─────────────────────────────────────────────

it('zeigt die Jahresbestleistungen für das gewählte Jahr, Name unverlinkt, noindex', function () {
    $club = makeClub_pfr7();
    $athlete = makeAthlete_pfr7($club, 'Anna', 'Beispiel', 'F');
    $group = makeSportClassGroup_pfr7('PI');
    assignToGroup_pfr7($group, 'S9');
    $meet = makeMeet_pfr7('2026-05-01');
    makeResult_pfr7($meet, makeSwimEvent_pfr7($meet, makeStrokeType_pfr7()), $athlete, $club, 777);

    $response = $this->get(route('public.annual-best.index', ['locale' => 'de', 'jahr' => 2026]))
        ->assertOk()
        ->assertSee('Beispiel, Anna')
        ->assertSee('777')
        ->assertSee(__('public.annual_best.heading'));

    $response->assertSee('noindex, nofollow', false);
    expect($response->getContent())->not->toContain('href="/de/athleten');
});

it('löst das Pfadsegment {jahr} korrekt auf, nicht die {locale}-Gruppe davor (Jahresbestleistungen)', function () {
    // Regressionstest: {locale}/bestleistungen/{jahr?} als impliziter Methodenparameter
    // ?string $jahr band positionsbasiert statt namensbasiert und bekam den locale-Wert 'de'
    // statt der Jahreszahl (Fund bei der manuellen Prüfung gegen die Dev-Datenbank) — mit
    // now()->year als Fallback fiel das nur auf, wenn das angeforderte Jahr vom laufenden
    // Kalenderjahr abweicht. Fix: $request->route('jahr') statt Methodenparameter.
    $club = makeClub_pfr7();
    $athlete = makeAthlete_pfr7($club, 'Alte', 'Bestleistung', 'F');
    $group = makeSportClassGroup_pfr7('PI');
    assignToGroup_pfr7($group, 'S9');
    $meet = makeMeet_pfr7('2019-05-01');
    makeResult_pfr7($meet, makeSwimEvent_pfr7($meet, makeStrokeType_pfr7()), $athlete, $club, 555);

    $this->get(route('public.annual-best.index', ['locale' => 'de', 'jahr' => 2019]))
        ->assertOk()
        ->assertSee('Bestleistung, Alte')
        ->assertSee('555');
});

// ── Public\CupRankingController ──────────────────────────────────────────────

it('zeigt die Cup-Gesamtwertung inkl. Rang, Name unverlinkt, noindex', /** @throws Throwable */ function () {
    $group = makeSportClassGroup_pfr7('PI');
    $cup = makeCup_pfr7(2026);
    $club = makeClub_pfr7();
    $athlete = makeAthlete_pfr7($club);
    makeDailyResult_pfr7($cup, makeMeet_pfr7('2026-06-01', $cup->id), $athlete, $club, $group, 420);
    app(OverallRankingService::class)->calculateForCup($cup);

    $response = $this->get(route('public.cup-ranking.index', ['locale' => 'de', 'jahr' => 2026]))
        ->assertOk()
        ->assertSee('Mustermann, Max')
        ->assertSee('420')
        ->assertSee(__('public.cup.heading'));

    $response->assertSee('noindex, nofollow', false);
    expect($response->getContent())->not->toContain('href="/de/athleten');
});

it('zeigt ohne Cups einen Hinweis statt eines Fehlers', function () {
    $this->get(route('public.cup-ranking.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee(__('public.cup.no_years'));
});

it('fällt bei unbekanntem Cup-Jahr auf das aktuellste vorhandene Jahr zurück', /** @throws Throwable */ function () {
    $group = makeSportClassGroup_pfr7('PI');
    $cup = makeCup_pfr7(2025);
    $club = makeClub_pfr7();
    $athlete = makeAthlete_pfr7($club);
    makeDailyResult_pfr7($cup, makeMeet_pfr7('2025-06-01', $cup->id), $athlete, $club, $group, 300);
    app(OverallRankingService::class)->calculateForCup($cup);

    $this->get(route('public.cup-ranking.index', ['locale' => 'de', 'jahr' => 1999]))
        ->assertOk()
        ->assertSee('300');
});

it('löst das Pfadsegment {jahr} korrekt auf, nicht die {locale}-Gruppe davor (Cup-Wertung)', /** @throws Throwable */ function () {
    // Regressionstest für denselben Fund wie bei den Jahresbestleistungen (siehe dort): mit nur
    // einem einzigen vorhandenen Cup-Jahr fällt die falsche Bindung nicht auf, weil der
    // "aktuellstes Jahr"-Fallback zufällig dasselbe Jahr liefert — deshalb hier zwei Cup-Jahre
    // und explizit das ÄLTERE angefordert.
    $group = makeSportClassGroup_pfr7('PI');

    $olderCup = makeCup_pfr7(2020);
    $olderClub = makeClub_pfr7();
    $olderAthlete = makeAthlete_pfr7($olderClub, 'Alt', 'Cupjahr');
    makeDailyResult_pfr7($olderCup, makeMeet_pfr7('2020-06-01', $olderCup->id), $olderAthlete, $olderClub, $group, 111);
    app(OverallRankingService::class)->calculateForCup($olderCup);

    $newerCup = makeCup_pfr7(2026);
    $newerClub = makeClub_pfr7();
    $newerAthlete = makeAthlete_pfr7($newerClub, 'Neu', 'Cupjahr');
    makeDailyResult_pfr7($newerCup, makeMeet_pfr7('2026-06-01', $newerCup->id), $newerAthlete, $newerClub, $group, 222);
    app(OverallRankingService::class)->calculateForCup($newerCup);

    $response = $this->get(route('public.cup-ranking.index', ['locale' => 'de', 'jahr' => 2020]))
        ->assertOk()
        ->assertSee('Cupjahr, Alt')
        ->assertSee('111');

    expect($response->getContent())->not->toContain('Cupjahr, Neu')->not->toContain('222');
});

// ── Public\QualifyingTimeController ──────────────────────────────────────────

it('zeigt nur die Qualifikationen der aktiven Richtzeitenliste', function () {
    $club = makeClub_pfr7();
    $activeList = makeQualifyingList_pfr7();
    $inactiveList = makeQualifyingList_pfr7(2025, false);
    $athleteActive = makeAthlete_pfr7($club, 'Aktuell', 'Qualifiziert');
    $athleteInactive = makeAthlete_pfr7($club, 'Alt', 'Qualifiziert');
    makeQualification_pfr7($activeList, makeStrokeType_pfr7(), $athleteActive, $club);
    makeQualification_pfr7($inactiveList, makeStrokeType_pfr7(), $athleteInactive, $club);

    $response = $this->get(route('public.qualifying-times.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Qualifiziert, Aktuell')
        ->assertDontSee('Qualifiziert, Alt');

    $response->assertSee('noindex, nofollow', false);
});

it('zeigt einen Hinweis ohne aktive Richtzeitenliste', function () {
    makeQualifyingList_pfr7(2025, false);

    $this->get(route('public.qualifying-times.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee(__('public.qualifying_times.empty'));
});

it('bietet keine Namenssuche an (§2.3 Punkt 3)', function () {
    makeQualifyingList_pfr7();

    $content = $this->get(route('public.qualifying-times.index', ['locale' => 'de']))
        ->assertOk()
        ->getContent();

    expect($content)->not->toContain('name="search"');
});

it('filtert serverseitig nach Sportklasse', function () {
    $club = makeClub_pfr7();
    $list = makeQualifyingList_pfr7();
    $athleteS9 = makeAthlete_pfr7($club, 'Neun', 'Klasse');
    $athleteS12 = makeAthlete_pfr7($club, 'Zwölf', 'Klasse');
    makeQualification_pfr7($list, makeStrokeType_pfr7(), $athleteS9, $club);
    makeQualification_pfr7($list, makeStrokeType_pfr7(), $athleteS12, $club, 'S12');

    $this->get(route('public.qualifying-times.index', ['locale' => 'de', 'sport_class' => 'S9']))
        ->assertOk()
        ->assertSee('Klasse, Neun')
        ->assertDontSee('Klasse, Zwölf');
});

it('fällt bei unbekannten Filterwerten still auf "kein Filter" zurück statt zu fehlen', function () {
    $club = makeClub_pfr7();
    $list = makeQualifyingList_pfr7();
    $athlete = makeAthlete_pfr7($club);
    makeQualification_pfr7($list, makeStrokeType_pfr7(), $athlete, $club);

    $this->get(route('public.qualifying-times.index', ['locale' => 'de', 'gender' => 'X', 'club_id' => 'abc']))
        ->assertOk()
        ->assertSee('Mustermann, Max');
});

// ── Navigation ────────────────────────────────────────────────────────────────

it('fasst Cup-Wertung, Startberechtigung und Jahresbestleistungen im Untermenü "Ranglisten" zusammen', function () {
    $content = $this->get(route('public.home', ['locale' => 'de']))
        ->assertOk()
        ->getContent();

    expect($content)->toContain(__('public.nav.rankings'))
        ->and($content)->toContain(route('public.cup-ranking.index', ['locale' => 'de']))
        ->and($content)->toContain(route('public.qualifying-times.index', ['locale' => 'de']))
        ->and($content)->toContain(route('public.annual-best.index', ['locale' => 'de']));
});

// ── robots.txt ────────────────────────────────────────────────────────────────

it('schließt die drei Ranglisten-Routen in robots.txt aus', function () {
    $robots = file_get_contents(public_path('robots.txt'));

    expect($robots)->toContain('/*/cup')
        ->and($robots)->toContain('/*/startberechtigung')
        ->and($robots)->toContain('/*/bestleistungen');
});

<?php

use App\Models\Athlete;
use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\DailyRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_cup4(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_cup4(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_cup4(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeClub_cup4(): Club
{
    return Club::create(['name' => 'Testclub', 'nation_id' => makeNation_cup4()->id]);
}

function makeAthlete_cup4(array $attrs = []): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'gender' => 'M',
        'nation_id' => makeNation_cup4()->id,
        'is_active' => true,
    ], $attrs));
}

function makeCup_cup4(): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

    $category = BaseTimeCategory::firstOrCreate(
        ['course' => 'LCM', 'gender' => 'M'],
        ['code' => 'LCM_M', 'label' => 'LCM Männer']
    );
    $discipline = BaseTimeDiscipline::firstOrCreate(
        ['stroke_type_id' => makeStrokeType_cup4()->id, 'distance' => 100, 'relay_count' => 1],
        ['code' => 'FREE_100']
    );
    $sportClass = BaseTimeSportClass::firstOrCreate(['code' => 'S9'], ['sort_order' => 1]);
    BaseTime::create([
        'base_time_version_id' => $version->id,
        'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id,
        'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => 10000, // 100,00s — identisch zur Schwimmzeit in makeResult_cup4() → 1000 Punkte
    ]);

    return Cup::create([
        'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);
}

function makeMeet_cup4(?Cup $cup): Meet
{
    return Meet::create([
        'name' => 'Testmeet', 'nation_id' => makeNation_cup4()->id,
        'course' => 'LCM', 'start_date' => '2026-06-01', 'cup_id' => $cup?->id,
    ]);
}

function makeStrokeType_cup4(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]
    );
}

function makeResult_cup4(Athlete $athlete, Club $club, Meet $meet, array $attrs = []): Result
{
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => makeStrokeType_cup4()->id, 'distance' => 100, 'gender' => 'A',
    ]);

    return Result::create(array_merge([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'swim_time' => 10000, 'points' => 999999, // 'points' bewusst falsch — wird ignoriert
    ], $attrs));
}

// ── show ──────────────────────────────────────────────────────────────────────

describe('show', function () {
    it('gibt 404 zurück, wenn das Meet keinem Cup zugeordnet ist', function () {
        $meet = makeMeet_cup4(null);

        $this->actingAs(makeClubUser_cup4())
            ->get(route('meets.cup-daily-ranking.show', $meet))
            ->assertNotFound();
    })->group('cup-wertung-p4');

    it('zeigt eine leere Ansicht, wenn noch keine Tageswertung berechnet wurde', function () {
        $cup = makeCup_cup4();
        $meet = makeMeet_cup4($cup);

        $this->actingAs(makeClubUser_cup4())
            ->get(route('meets.cup-daily-ranking.show', $meet))
            ->assertOk()
            ->assertSee('noch keine Tageswertung berechnet');
    })->group('cup-wertung-p4');

    it('zeigt die berechnete Tageswertung inkl. Rang', function () {
        SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        SportClassGroupMember::create([
            'sport_class_group_id' => SportClassGroup::where('code', 'PI')->value('id'),
            'sport_class' => 'S9',
        ]);
        $cup = makeCup_cup4();
        $meet = makeMeet_cup4($cup);
        $club = makeClub_cup4();
        $athlete = makeAthlete_cup4();
        makeResult_cup4($athlete, $club, $meet);

        app(DailyRankingService::class)->calculateForMeet($meet);

        $this->actingAs(makeClubUser_cup4())
            ->get(route('meets.cup-daily-ranking.show', $meet))
            ->assertOk()
            ->assertSee('Mustermann, Max')
            ->assertSee('1000');
    })->group('cup-wertung-p4');
});

// ── calculate ─────────────────────────────────────────────────────────────────

describe('calculate', function () {
    it('Club-User bekommt 403', function () {
        $cup = makeCup_cup4();
        $meet = makeMeet_cup4($cup);

        $this->actingAs(makeClubUser_cup4())
            ->post(route('meets.cup-daily-ranking.calculate', $meet))
            ->assertForbidden();
    })->group('cup-wertung-p4');

    it('Admin kann die Tageswertung berechnen und wird zur Anzeige weitergeleitet', function () {
        SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        SportClassGroupMember::create([
            'sport_class_group_id' => SportClassGroup::where('code', 'PI')->value('id'),
            'sport_class' => 'S9',
        ]);
        $cup = makeCup_cup4();
        $meet = makeMeet_cup4($cup);
        $club = makeClub_cup4();
        $athlete = makeAthlete_cup4();
        makeResult_cup4($athlete, $club, $meet);

        $this->actingAs(makeAdmin_cup4())
            ->post(route('meets.cup-daily-ranking.calculate', $meet))
            ->assertRedirect(route('meets.cup-daily-ranking.show', $meet));

        expect(CupDailyResult::where('meet_id', $meet->id)->count())->toBe(1);
    })->group('cup-wertung-p4');
});

// ── Damen/Herren gemeinsam (Schritt: Jugend-/Gender-Einstellung) ────────────

describe('gemeinsame Damen/Herren-Wertung', function () {
    it('zeigt eine gemeinsame Bracket "Damen & Herren", wenn für die Gruppe aktiviert', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        // Ohne diese Zuordnung liefert resolveBaseSportClassGroup() null, die
        // Ergebnisse fallen aus der Tageswertung und die Ansicht bleibt leer.
        SportClassGroupMember::create([
            'sport_class_group_id' => $group->id,
            'sport_class' => 'S9',
        ]);
        $cup = makeCup_cup4();
        $cup->groupSettings()->create(['sport_class_group_id' => $group->id, 'gender_combined' => true]);
        $meet = makeMeet_cup4($cup);
        $club = makeClub_cup4();

        $man = makeAthlete_cup4(['gender' => 'M', 'first_name' => 'Herr']);
        $woman = makeAthlete_cup4(['gender' => 'F', 'first_name' => 'Frau']);
        makeResult_cup4($man, $club, $meet);
        makeResult_cup4($woman, $club, $meet);

        app(DailyRankingService::class)->calculateForMeet($meet);

        $this->actingAs(makeClubUser_cup4())
            ->get(route('meets.cup-daily-ranking.show', $meet))
            ->assertOk()
            ->assertSee('Damen & Herren')
            ->assertSee('Mustermann, Herr')
            ->assertSee('Mustermann, Frau');
    })->group('cup-wertung-p4');
});

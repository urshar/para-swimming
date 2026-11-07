<?php

use App\Enums\RoleTemplate;
use App\Livewire\CupClubRanking;
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
use App\Models\User;
use App\Services\DailyRankingService;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * Prognose der Vereinswertung, Phase 4 (docs/specs/cup-club-ranking-forecast.md §7/§9): Umschalter
 * in der Livewire-Komponente CupClubRanking — nur für Admins und nur mit Prognose-Meet.
 *
 * Basiswert: 100 m Freistil LCM Herren S9 = 100,00 s — 100,00 s ergeben 1000 Punkte, 125,00 s
 * ergeben 512 Punkte.
 */
uses(RefreshDatabase::class)->group('cup-club-ranking-forecast-p4');

// ── Helpers ──────────────────────────────────────────────────────────────────

function nextSeq_fc4(): int
{
    static $n = 0;

    return ++$n;
}

function admin_fc4(): User
{
    return User::forceCreate(['name' => 'Admin', 'email' => 'admin'.nextSeq_fc4().'_fc4@example.test',
        'password' => bcrypt('secret'), 'is_admin' => true]);
}

/**
 * Vereinsbenutzer mit Leserecht auf die Cup-Wertung (Vorlage "Verein"), aber ohne Admin-Recht.
 *
 * @throws Throwable
 */
function reader_fc4(): User
{
    $user = User::forceCreate(['name' => 'Leser', 'email' => 'reader'.nextSeq_fc4().'_fc4@example.test',
        'password' => bcrypt('secret'), 'is_admin' => false]);
    app(PermissionTemplateService::class)->applyTemplate($user, RoleTemplate::Club);

    return $user;
}

function nation_fc4(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'AUT', 'name_en' => 'AUT', 'is_active' => true]);
}

function stroke_fc4(): StrokeType
{
    return StrokeType::firstOrCreate(['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]);
}

function club_fc4(string $name): Club
{
    return Club::create(['name' => $name, 'nation_id' => nation_fc4()->id]);
}

function athlete_fc4(): Athlete
{
    $seq = nextSeq_fc4();

    return Athlete::create(['first_name' => "Vorname$seq", 'last_name' => "Nachname$seq", 'gender' => 'M',
        'nation_id' => nation_fc4()->id, 'is_active' => true]);
}

function cup_fc4(): Cup
{
    $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
    SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);

    $version = BaseTimeVersion::create(['label' => 'V2026', 'valid_from' => '2026-01-01']);
    $discipline = BaseTimeDiscipline::create(['stroke_type_id' => stroke_fc4()->id, 'distance' => 100,
        'relay_count' => 1, 'code' => 'FREE_100']);
    $category = BaseTimeCategory::create(['course' => 'LCM', 'gender' => 'M', 'code' => 'LCM_M', 'label' => 'LCM Männer']);
    $sportClass = BaseTimeSportClass::create(['code' => 'S9', 'sort_order' => 1]);
    BaseTime::create(['base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id, 'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => 10000]);

    return Cup::create(['year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 2, 'best_of_count' => 3]);
}

function meet_fc4(Cup $cup, string $name, string $date): Meet
{
    return Meet::create(['name' => $name, 'nation_id' => nation_fc4()->id, 'course' => 'LCM', 'start_date' => $date,
        'cup_id' => $cup->id]);
}

function event_fc4(Meet $meet, int $distance): SwimEvent
{
    return SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_fc4()->id, 'distance' => $distance,
        'relay_count' => 1, 'round' => 'TIM', 'gender' => 'M']);
}

function result_fc4(Meet $meet, int $distance, Athlete $athlete, Club $club, int $swimTime): Result
{
    return Result::create(['meet_id' => $meet->id, 'swim_event_id' => event_fc4($meet, $distance)->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'swim_time' => $swimTime, 'sport_class' => 'S9']);
}

function entry_fc4(Meet $meet, int $distance, Athlete $athlete, Club $club, ?int $entryTime, string $entryCourse): Entry
{
    return Entry::create(['meet_id' => $meet->id, 'swim_event_id' => event_fc4($meet, $distance)->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'entry_time' => $entryTime,
        'entry_time_code' => $entryTime === null ? 'NT' : null, 'sport_class' => 'S9', 'status' => null,
        'entry_course' => $entryCourse]);
}

/**
 * Cup mit einem echten Meet (Verein A: 1000 Punkte, 2 Starts; Verein B: 512 Punkte, 1 Start) und
 * einem Prognose-Meet "Cup-Finale" (Verein B: 1000 Punkte aus Meldung, eine Meldung aus der
 * Kurzbahn, eine NT-Meldung).
 *
 * @return array{cup: Cup, athleteB: Athlete}
 *
 * @throws Throwable
 */
function scenario_fc4(): array
{
    $cup = cup_fc4();
    $real = meet_fc4($cup, 'Cup-Auftakt', '2026-05-01');
    $forecast = meet_fc4($cup, 'Cup-Finale', '2026-11-20');
    $clubA = club_fc4('Verein Alpha');
    $clubB = club_fc4('Verein Beta');
    $athleteA = athlete_fc4();
    $athleteB = athlete_fc4();
    $athleteB2 = athlete_fc4();

    result_fc4($real, 100, $athleteA, $clubA, 10000);
    result_fc4($real, 200, $athleteA, $clubA, 10000);
    result_fc4($real, 100, $athleteB, $clubB, 12500);
    app(DailyRankingService::class)->calculateForMeet($real);

    entry_fc4($forecast, 100, $athleteB, $clubB, 10000, 'LCM');
    entry_fc4($forecast, 50, $athleteB, $clubB, null, 'LCM');
    entry_fc4($forecast, 100, $athleteB2, $clubB, 10000, 'SCM');

    return ['cup' => $cup, 'athleteB' => $athleteB];
}

// ── Sichtbarkeit ─────────────────────────────────────────────────────────────

it('zeigt Admins den Prognose-Umschalter, wenn es ein Prognose-Meet gibt', /** @throws Throwable */ function () {
    ['cup' => $cup] = scenario_fc4();

    $this->actingAs(admin_fc4())
        ->get(route('cups.club-ranking.show', $cup))
        ->assertOk()
        ->assertSee('toggleForecast')
        ->assertSee('aria-pressed="false"', false)
        ->assertDontSee('Voraussichtliche Endwertung');
});

it('zeigt Admins keinen Prognose-Umschalter ohne Prognose-Meet', function () {
    $cup = cup_fc4();
    result_fc4(meet_fc4($cup, 'Cup-Auftakt', '2026-05-01'), 100, athlete_fc4(), club_fc4('Verein Alpha'), 10000);

    $this->actingAs(admin_fc4())
        ->get(route('cups.club-ranking.show', ['cup' => $cup, 'forecast' => 1]))
        ->assertOk()
        ->assertDontSee('toggleForecast')
        ->assertDontSee('Voraussichtliche Endwertung');
});

it('zeigt Nicht-Admins weder Umschalter noch Prognose, auch nicht per Query-Parameter', /** @throws Throwable */ function () {
    ['cup' => $cup] = scenario_fc4();

    $this->actingAs(reader_fc4())
        ->get(route('cups.club-ranking.show', ['cup' => $cup, 'forecast' => 1]))
        ->assertOk()
        ->assertDontSee('toggleForecast')
        ->assertDontSee('Voraussichtliche Endwertung');
});

it('verweigert Nicht-Admins das Einschalten der Prognose', /** @throws Throwable */ function () {
    ['cup' => $cup] = scenario_fc4();

    Livewire::actingAs(reader_fc4())
        ->test(CupClubRanking::class, ['cup' => $cup])
        ->call('toggleForecast')
        ->assertForbidden();
});

it('wertet eine vom Client gesetzte Prognose-Eigenschaft für Nicht-Admins nicht aus', /** @throws Throwable */ function () {
    ['cup' => $cup] = scenario_fc4();

    Livewire::actingAs(reader_fc4())
        ->test(CupClubRanking::class, ['cup' => $cup])
        ->set('forecast', true)
        ->assertDontSee('Voraussichtliche Endwertung')
        ->assertDontSee('Rang jetzt');
});

// ── Prognose-Anzeige ─────────────────────────────────────────────────────────

it('zeigt Admins die prognostizierte Startwertung mit Rangänderung und Differenz', /** @throws Throwable */ function () {
    ['cup' => $cup] = scenario_fc4();

    Livewire::actingAs(admin_fc4())
        ->test(CupClubRanking::class, ['cup' => $cup])
        ->call('setSystem', 'start')
        ->call('toggleForecast')
        ->assertSet('forecast', true)
        ->assertSee('Voraussichtliche Endwertung')
        ->assertSee('Cup-Finale (20.11.2026)')
        ->assertSee('Rang jetzt')
        ->assertSee('▲ 1')
        ->assertSee('1 Platz besser')
        ->assertSee('▼ 1')
        ->assertSee('+ 3')
        ->assertSee('aria-pressed="true"', false)
        ->assertDontSee('club-ranking/pdf')
        ->assertSeeInOrder(['Verein Beta', 'Verein Alpha']);
});

it('kennzeichnet in der Leistungswertungs-Prognose Meet-Punkte aus Meldezeiten und nicht bewertbare Meldungen', /** @throws Throwable */ function () {
    ['cup' => $cup] = scenario_fc4();

    Livewire::actingAs(admin_fc4())
        ->test(CupClubRanking::class, ['cup' => $cup])
        ->call('toggleForecast')
        ->assertSee('(Meldezeit)')
        ->assertSee('(Meldezeit, andere Bahnlänge)')
        ->assertSee('keine Meldezeit (NT): 1')
        ->assertSee('+ 1.800,00')
        ->assertSeeInOrder(['Verein Beta', 'Verein Alpha']);
});

it('schaltet die Prognose wieder aus und zeigt die aktuelle Wertung', /** @throws Throwable */ function () {
    ['cup' => $cup] = scenario_fc4();

    Livewire::actingAs(admin_fc4())
        ->test(CupClubRanking::class, ['cup' => $cup])
        ->call('toggleForecast')
        ->call('toggleForecast')
        ->assertSet('forecast', false)
        ->assertDontSee('Voraussichtliche Endwertung')
        ->assertDontSee('Rang jetzt')
        ->assertSeeInOrder(['Verein Alpha', 'Verein Beta']);
});

it('öffnet die Prognose für Admins direkt über den Query-Parameter', /** @throws Throwable */ function () {
    ['cup' => $cup] = scenario_fc4();

    $this->actingAs(admin_fc4())
        ->get(route('cups.club-ranking.show', ['cup' => $cup, 'forecast' => 1]))
        ->assertOk()
        ->assertSee('Voraussichtliche Endwertung');
});

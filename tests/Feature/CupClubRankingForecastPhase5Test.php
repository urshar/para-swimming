<?php

use App\Enums\RoleTemplate;
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
use App\Services\PdfExportService;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;

/*
 * Prognose der Vereinswertung, Phase 5 (docs/specs/cup-club-ranking-forecast.md §9): PDF-Export
 * der Prognose über cups.club-ranking.pdf mit forecast=1 — nur für Admins.
 *
 * Für Inhaltsprüfungen ersetzt fakePdf_fc5() den PdfExportService durch eine Variante, die das
 * gerenderte HTML statt des PDFs liefert; ein Test läuft bewusst über das echte dompdf.
 *
 * Basiswert: 100 m Freistil LCM Herren S9 = 100,00 s — 100,00 s ergeben 1000 Punkte, 125,00 s
 * ergeben 512 Punkte.
 */
uses(RefreshDatabase::class)->group('cup-club-ranking-forecast-p5');

// ── Helpers ──────────────────────────────────────────────────────────────────

function nextSeq_fc5(): int
{
    static $n = 0;

    return ++$n;
}

function admin_fc5(): User
{
    return User::forceCreate(['name' => 'Admin', 'email' => 'admin'.nextSeq_fc5().'_fc5@example.test',
        'password' => bcrypt('secret'), 'is_admin' => true]);
}

/**
 * Vereinsbenutzer mit Leserecht auf die Cup-Wertung (Vorlage "Verein"), aber ohne Admin-Recht.
 *
 * @throws Throwable
 */
function reader_fc5(): User
{
    $user = User::forceCreate(['name' => 'Leser', 'email' => 'reader'.nextSeq_fc5().'_fc5@example.test',
        'password' => bcrypt('secret'), 'is_admin' => false]);
    app(PermissionTemplateService::class)->applyTemplate($user, RoleTemplate::Club);

    return $user;
}

/** PdfExportService, der statt des PDFs das gerenderte HTML samt Dateinamen liefert. */
function fakePdf_fc5(): void
{
    app()->instance(PdfExportService::class, new readonly class extends PdfExportService
    {
        public function stream(string $view, array $data, string $filename, string $orientation = 'portrait'): Response
        {
            return new Response(view($view, $data)->render(), 200, ['X-Pdf-Filename' => $filename]);
        }
    });
}

function nation_fc5(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'AUT', 'name_en' => 'AUT', 'is_active' => true]);
}

function stroke_fc5(): StrokeType
{
    return StrokeType::firstOrCreate(['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]);
}

function club_fc5(string $name): Club
{
    return Club::create(['name' => $name, 'nation_id' => nation_fc5()->id]);
}

function athlete_fc5(): Athlete
{
    $seq = nextSeq_fc5();

    return Athlete::create(['first_name' => "Vorname$seq", 'last_name' => "Nachname$seq", 'gender' => 'M',
        'nation_id' => nation_fc5()->id, 'is_active' => true]);
}

function cup_fc5(): Cup
{
    $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
    SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);

    $version = BaseTimeVersion::create(['label' => 'V2026', 'valid_from' => '2026-01-01']);
    $discipline = BaseTimeDiscipline::create(['stroke_type_id' => stroke_fc5()->id, 'distance' => 100,
        'relay_count' => 1, 'code' => 'FREE_100']);
    $category = BaseTimeCategory::create(['course' => 'LCM', 'gender' => 'M', 'code' => 'LCM_M', 'label' => 'LCM Männer']);
    $sportClass = BaseTimeSportClass::create(['code' => 'S9', 'sort_order' => 1]);
    BaseTime::create(['base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id, 'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => 10000]);

    return Cup::create(['year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 2, 'best_of_count' => 3]);
}

function meet_fc5(Cup $cup, string $name, string $date): Meet
{
    return Meet::create(['name' => $name, 'nation_id' => nation_fc5()->id, 'course' => 'LCM', 'start_date' => $date,
        'cup_id' => $cup->id]);
}

function event_fc5(Meet $meet, int $distance): SwimEvent
{
    return SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_fc5()->id, 'distance' => $distance,
        'relay_count' => 1, 'round' => 'TIM', 'gender' => 'M']);
}

function result_fc5(Meet $meet, int $distance, Athlete $athlete, Club $club, int $swimTime): Result
{
    return Result::create(['meet_id' => $meet->id, 'swim_event_id' => event_fc5($meet, $distance)->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'swim_time' => $swimTime, 'sport_class' => 'S9']);
}

function entry_fc5(Meet $meet, int $distance, Athlete $athlete, Club $club, ?int $entryTime, string $entryCourse): Entry
{
    return Entry::create(['meet_id' => $meet->id, 'swim_event_id' => event_fc5($meet, $distance)->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'entry_time' => $entryTime,
        'entry_time_code' => $entryTime === null ? 'NT' : null, 'sport_class' => 'S9', 'status' => null,
        'entry_course' => $entryCourse]);
}

/**
 * Cup mit einem echten Meet (Verein Alpha: 1000 Punkte, 2 Starts; Verein Beta: 512 Punkte,
 * 1 Start) und einem Prognose-Meet "Cup-Finale" (Verein Beta: 1000 Punkte aus Meldung, eine
 * Meldung aus der Kurzbahn, eine NT-Meldung).
 *
 * @throws Throwable
 */
function scenario_fc5(): Cup
{
    $cup = cup_fc5();
    $real = meet_fc5($cup, 'Cup-Auftakt', '2026-05-01');
    $forecast = meet_fc5($cup, 'Cup-Finale', '2026-11-20');
    $clubA = club_fc5('Verein Alpha');
    $clubB = club_fc5('Verein Beta');
    $athleteA = athlete_fc5();
    $athleteB = athlete_fc5();
    $athleteB2 = athlete_fc5();

    result_fc5($real, 100, $athleteA, $clubA, 10000);
    result_fc5($real, 200, $athleteA, $clubA, 10000);
    result_fc5($real, 100, $athleteB, $clubB, 12500);
    app(DailyRankingService::class)->calculateForMeet($real);

    entry_fc5($forecast, 100, $athleteB, $clubB, 10000, 'LCM');
    entry_fc5($forecast, 50, $athleteB, $clubB, null, 'LCM');
    entry_fc5($forecast, 100, $athleteB2, $clubB, 10000, 'SCM');

    return $cup;
}

// ── Zugriff ──────────────────────────────────────────────────────────────────

it('liefert Admins das Prognose-PDF über das echte dompdf', /** @throws Throwable */ function () {
    $cup = scenario_fc5();

    $response = $this->actingAs(admin_fc5())
        ->get(route('cups.club-ranking.pdf', ['cup' => $cup, 'system' => 'performance', 'detail' => 1, 'forecast' => 1]));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF')
        ->and($response->headers->get('content-disposition'))
        ->toContain("cup-vereinswertung-prognose-performance-$cup->id.pdf");
});

it('verweigert Nicht-Admins das Prognose-PDF, das normale PDF aber nicht', /** @throws Throwable */ function () {
    $cup = scenario_fc5();
    fakePdf_fc5();
    $reader = reader_fc5();

    $this->actingAs($reader)
        ->get(route('cups.club-ranking.pdf', ['cup' => $cup, 'system' => 'start', 'forecast' => 1]))
        ->assertForbidden();

    $this->actingAs($reader)
        ->get(route('cups.club-ranking.pdf', ['cup' => $cup, 'system' => 'start']))
        ->assertOk()
        ->assertDontSee('Prognose');
});

// ── Inhalt ───────────────────────────────────────────────────────────────────

it('zeigt im Prognose-PDF der Startwertung Rang jetzt, Rangänderung und Differenz', /** @throws Throwable */ function () {
    $cup = scenario_fc5();
    fakePdf_fc5();

    $this->actingAs(admin_fc5())
        ->get(route('cups.club-ranking.pdf', ['cup' => $cup, 'system' => 'start', 'forecast' => 1]))
        ->assertOk()
        ->assertHeader('X-Pdf-Filename', "cup-vereinswertung-prognose-start-$cup->id.pdf")
        ->assertSeeInOrder(['ÖBSV Cup 2026', 'Startwertung', 'Prognose'])
        ->assertSee('Cup-Finale (20.11.2026)')
        ->assertSee('Rang jetzt')
        ->assertSee('(+1)')
        ->assertSee('(-1)')
        ->assertSee('+ 3')
        ->assertSeeInOrder(['Verein Beta', 'Verein Alpha']);
});

it('kennzeichnet im Prognose-PDF der Leistungswertung Meet-Punkte aus Meldezeiten', /** @throws Throwable */ function () {
    $cup = scenario_fc5();
    fakePdf_fc5();

    $this->actingAs(admin_fc5())
        ->get(route('cups.club-ranking.pdf', ['cup' => $cup, 'system' => 'performance', 'detail' => 1, 'forecast' => 1]))
        ->assertOk()
        ->assertSeeInOrder(['ÖBSV Cup 2026', 'Leistungswertung', 'Prognose'])
        ->assertSee('(Meldezeit)')
        ->assertSee('(Meldezeit, andere Bahnlänge)')
        ->assertSee('keine Meldezeit (NT): 1')
        ->assertSee('+ 1.800,00')
        ->assertSeeInOrder(['Verein Beta', 'Verein Alpha']);
});

it('liefert ohne Prognose-Meet trotz forecast=1 das normale PDF', function () {
    $cup = cup_fc5();
    result_fc5(meet_fc5($cup, 'Cup-Auftakt', '2026-05-01'), 100, athlete_fc5(), club_fc5('Verein Alpha'), 10000);
    fakePdf_fc5();

    $this->actingAs(admin_fc5())
        ->get(route('cups.club-ranking.pdf', ['cup' => $cup, 'system' => 'start', 'forecast' => 1]))
        ->assertOk()
        ->assertHeader('X-Pdf-Filename', "cup-vereinswertung-start-$cup->id.pdf")
        ->assertDontSee('Prognose')
        ->assertDontSee('Rang jetzt');
});

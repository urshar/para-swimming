<?php

use App\Livewire\WpsTalentReport;
use App\Models\Athlete;
use App\Models\BaseTimeSportClass;
use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Models\WpsPointParameter;
use App\Models\WpsPointVersion;
use App\Services\WpsTalentReportService;
use App\Support\WpsRankingFilter;
use App\Support\WpsTalentReportConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('wps-rankings-p6');

// ── Helper (Suffix _wr6 gegen Namenskollisionen) ─────────────────────────────

function nation_wr6(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function stroke_wr6(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => 'FREE'],
        ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']
    );
}

function athlete_wr6(string $nachname, ?string $geburtsdatum): Athlete
{
    return Athlete::query()->create([
        'club_id' => Club::query()->orderBy('id')->value('id'),
        'nation_id' => nation_wr6()->id,
        'first_name' => 'Test',
        'last_name' => $nachname,
        'birth_date' => $geburtsdatum,
        'gender' => 'F',
    ]);
}

function meet_wr6(string $name, string $datum, string $course = 'SCM'): Meet
{
    return Meet::query()->create([
        'name' => $name,
        'city' => 'Wien',
        'nation_id' => nation_wr6()->id,
        'course' => $course,
        'start_date' => $datum,
    ]);
}

function event_wr6(Meet $meet, int $distance): SwimEvent
{
    return SwimEvent::query()->create([
        'meet_id' => $meet->id,
        'stroke_type_id' => stroke_wr6()->id,
        'event_number' => 1,
        'gender' => 'F',
        'distance' => $distance,
        'relay_count' => 1,
    ]);
}

function result_wr6(SwimEvent $event, Athlete $athlete, int $zeit, ?int $punkte): Result
{
    return Result::query()->create([
        'meet_id' => $event->meet_id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => $zeit,
        'sport_class' => 'S9',
        'wps_points' => $punkte,
        'wps_calculation_type' => Result::WPS_TYPE_ESTIMATED,
    ]);
}

/**
 * Parametersatz je Strecke.
 *
 * parameter_c skaliert mit der Streckenlänge — die Gompertz-Funktion wird sonst weit im
 * Sättigungsbereich ausgewertet und liefert null Punkte. Der Wert für 100 m auf eine
 * 200-m-Zeit angewandt ergibt 0, und die Zeile fiele mangels Normpunkten ganz weg.
 */
function pointVersion_wr6(int $distance = 100): WpsPointVersion
{
    // Grob proportional zur Strecke, so wie die echten Parametersätze.
    $parameterC = 515.385 * $distance / 100;

    $version = WpsPointVersion::query()->firstOrCreate(
        ['year' => 2026, 'version' => '1'],
        ['label' => 'WPS 2026', 'status' => WpsPointVersion::STATUS_ACTIVE, 'valid_from' => '2020-01-01']
    );

    WpsPointParameter::query()->firstOrCreate([
        'wps_point_version_id' => $version->id,
        'course' => WpsPointParameter::COURSE_LCM,
        'gender' => 'F',
        'stroke_type_id' => stroke_wr6()->id,
        'distance' => $distance,
        'relay_count' => 1,
        'sport_class' => 'S9',
    ], [
        'parameter_a' => 1200,
        'parameter_b' => 6.19,
        'parameter_c' => $parameterC,
    ]);

    return $version;
}

function championship_wr6(int $distance = 100, ?int $mqs = 7000, ?int $met = 7600): Championship
{
    $championship = Championship::query()->create([
        'name' => 'EM 2026',
        'short_name' => 'EM 2026',
        'type' => Championship::TYPE_EC,
        'year' => 2026,
        'course' => Championship::COURSE_LCM,
        'qualification_start' => '2025-01-01',
        'qualification_end' => '2026-07-06',
    ]);

    if ($mqs !== null) {
        ChampionshipStandard::query()->create([
            'championship_id' => $championship->id,
            'stroke_type_id' => stroke_wr6()->id,
            'distance' => $distance,
            'gender' => 'F',
            'sport_class' => 'S9',
            'mqs_centiseconds' => $mqs,
            'met_centiseconds' => $met,
        ]);
    }

    return $championship;
}

/**
 * Die Jahre werden bewusst ohne Vorgabewerte übergeben: Ob eine Auswertung ein- oder
 * mehrjährig ist, gehört in jedem Testfall sichtbar zur Absicht.
 */
function config_wr6(Championship $reference, int $von, int $bis): WpsTalentReportConfiguration
{
    return new WpsTalentReportConfiguration(
        fromYear: $von,
        toYear: $bis,
        reference: $reference,
    );
}

beforeEach(function () {
    $this->service = app(WpsTalentReportService::class);
    BaseTimeSportClass::query()->firstOrCreate(['code' => 'S9'], ['sort_order' => 9]);
    Club::query()->create(['name' => 'WAT', 'short_name' => 'WAT', 'nation_id' => nation_wr6()->id]);
});

// ── Schwelle (§6.6.2) ────────────────────────────────────────────────────────

it('leitet die Schwelle aus der Punktzahl der Normzeit ab', function () {
    pointVersion_wr6();
    $championship = championship_wr6();

    $meet = meet_wr6('Kurzbahn', '2026-03-13');
    result_wr6(event_wr6($meet, 100), athlete_wr6('Jung', '2009-06-01'), 7500, 700);

    $zeile = $this->service->entries(config_wr6($championship, 2026, 2026))->sole();

    // Schwelle = Normpunkte × 85 / 100, abgerundet.
    expect($zeile->thresholdPoints)
        ->toBe((int) floor($zeile->normPoints * WpsTalentReportConfiguration::DEFAULT_YOUTH_THRESHOLD / 100))
        ->and($zeile->normPoints)->toBeGreaterThan(0);
});

it('verwendet je Altersgruppe den zugehörigen Prozentsatz', function () {
    $config = config_wr6(championship_wr6(), 2026, 2026);

    // Jugend 85 %, Allgemein 95 % — die höhere Schwelle gilt für die ältere Gruppe.
    expect($config->thresholdPoints(1000, WpsTalentReportConfiguration::GROUP_YOUTH))->toBe(850)
        ->and($config->thresholdPoints(1000, WpsTalentReportConfiguration::GROUP_GENERAL))->toBe(950);
});

it('erkennt das Erreichen der Schwelle und weist den Abstand aus', function () {
    pointVersion_wr6();
    $championship = championship_wr6();
    $meet = meet_wr6('Kurzbahn', '2026-03-13');

    $normpunkte = app(App\Services\WpsPointCalculator::class)->pointsForTime(
        7000, 'LCM', 'F', stroke_wr6()->id, 100, 'S9',
        WpsPointVersion::query()->sole(),
    );
    $schwelle = (int) floor($normpunkte * 85 / 100);

    result_wr6(event_wr6($meet, 100), athlete_wr6('Drueber', '2009-06-01'), 7100, $schwelle + 10);

    $zeile = $this->service->entries(config_wr6($championship, 2026, 2026))->sole();

    expect($zeile->reachesThreshold())->toBeTrue()
        ->and($zeile->gapToThreshold())->toBe(10)
        ->and($zeile->formattedGap())->toBe('+10');
});

// ── Altersgruppen (§6.6.3) ───────────────────────────────────────────────────

it('führt einen Athleten bei mehrjährigem Zeitraum in beiden Altersgruppen', function () {
    pointVersion_wr6();
    pointVersion_wr6(200);

    $championship = championship_wr6();
    ChampionshipStandard::query()->create([
        'championship_id' => $championship->id,
        'stroke_type_id' => stroke_wr6()->id,
        'distance' => 200,
        'gender' => 'F',
        'sport_class' => 'S9',
        'mqs_centiseconds' => 15000,
    ]);

    // Geboren 2007: 2025 wird sie 18 (Jugend), 2026 wird sie 19 (Allgemein).
    $athletin = athlete_wr6('Uebergang', '2007-06-01');

    result_wr6(event_wr6(meet_wr6('Jahr 2025', '2025-03-13'), 100), $athletin, 7100, 700);
    result_wr6(event_wr6(meet_wr6('Jahr 2026', '2026-03-13'), 200), $athletin, 15500, 690);

    $bericht = $this->service->report(config_wr6($championship, 2025, 2026));

    // Maßgeblich ist das Alter im ERGEBNISJAHR, nicht am Zeitraumende. Das sieht aus wie ein
    // Fehler, ist aber gewollt und bildet die Entwicklung korrekt ab.
    expect($bericht->keys()->sort()->values()->all())
        ->toBe([WpsTalentReportConfiguration::GROUP_GENERAL, WpsTalentReportConfiguration::GROUP_YOUTH])
        ->and($bericht[WpsTalentReportConfiguration::GROUP_YOUTH])->toHaveCount(1)
        ->and($bericht[WpsTalentReportConfiguration::GROUP_GENERAL])->toHaveCount(1);
});

it('ordnet nach dem Alter zum 31. Dezember des Ergebnisjahres', function () {
    $config = config_wr6(championship_wr6(), 2026, 2026);

    expect($config->groupForAge(18))->toBe(WpsTalentReportConfiguration::GROUP_YOUTH)
        ->and($config->groupForAge(19))->toBe(WpsTalentReportConfiguration::GROUP_GENERAL);
});

it('weist Athleten ohne Geburtsdatum als Sammelposten aus', function () {
    pointVersion_wr6();
    $championship = championship_wr6();
    $meet = meet_wr6('Kurzbahn', '2026-03-13');

    result_wr6(event_wr6($meet, 100), athlete_wr6('MitDatum', '2009-06-01'), 7100, 700);
    result_wr6(event_wr6($meet, 100), athlete_wr6('OhneDatum', null), 7000, 720);

    expect($this->service->entries(config_wr6($championship, 2026, 2026)))->toHaveCount(1)
        ->and($this->service->withoutBirthDate(config_wr6($championship, 2026, 2026)))->toHaveCount(1);
});

// ── Ausgabe (§6.6.4) ─────────────────────────────────────────────────────────

it('führt eine Zeile je Athlet und Bewerb', function () {
    pointVersion_wr6();
    pointVersion_wr6(200);

    $championship = championship_wr6();
    ChampionshipStandard::query()->create([
        'championship_id' => $championship->id,
        'stroke_type_id' => stroke_wr6()->id,
        'distance' => 200,
        'gender' => 'F',
        'sport_class' => 'S9',
        'mqs_centiseconds' => 15000,
    ]);

    $athletin = athlete_wr6('Zweifach', '2009-06-01');
    $meet = meet_wr6('Kurzbahn', '2026-03-13');

    result_wr6(event_wr6($meet, 100), $athletin, 7100, 700);
    result_wr6(event_wr6($meet, 200), $athletin, 15500, 690);

    // Ein Athlet kann mehrfach in der Liste stehen — die Information, in welchen Bewerben
    // jemand über der Schwelle liegt, ist für die Förderentscheidung wesentlich.
    expect($this->service->entries(config_wr6($championship, 2026, 2026)))->toHaveCount(2);
});

it('lässt Bewerbe ohne Norm in der Referenz weg', function () {
    pointVersion_wr6(50);
    $championship = championship_wr6(); // Norm nur für 100 m

    $meet = meet_wr6('Kurzbahn', '2026-03-13');
    result_wr6(event_wr6($meet, 50), athlete_wr6('OhneNorm', '2009-06-01'), 3500, 700);

    // Eine Zeile ohne Bezugsgröße wäre nicht interpretierbar.
    expect($this->service->entries(config_wr6($championship, 2026, 2026)))->toBeEmpty();
});

it('nimmt je Athlet und Bewerb die beste Leistung des Zeitraums', function () {
    pointVersion_wr6();
    $championship = championship_wr6();
    $athletin = athlete_wr6('Mehrfachstarter', '2009-06-01');

    result_wr6(event_wr6(meet_wr6('Erstes', '2026-02-01'), 100), $athletin, 7500, 650);
    result_wr6(event_wr6(meet_wr6('Zweites', '2026-05-01'), 100), $athletin, 7100, 720);

    $zeile = $this->service->entries(config_wr6($championship, 2026, 2026))->sole();

    expect($zeile->points)->toBe(720)
        ->and($zeile->meetName)->toBe('Zweites');
});

it('nennt Referenznorm und Schwellen in der Beschreibung', function () {
    $beschreibung = config_wr6(championship_wr6(), 2026, 2026)->describe();

    // Ein Prozentwert ohne Angabe seiner Bezugsgröße ist wertlos.
    expect($beschreibung)->toContain('EM 2026')
        ->and($beschreibung)->toContain('MQS')
        ->and($beschreibung)->toContain('85')
        ->and($beschreibung)->toContain('95');
});

it('liefert ohne gültige Punkteversion keine Auswertung statt einer mit Schwelle null', function () {
    // Keine Punkteversion angelegt.
    $championship = championship_wr6();
    result_wr6(event_wr6(meet_wr6('Kurzbahn', '2026-03-13'), 100), athlete_wr6('Egal', '2009-06-01'), 7100, 700);

    expect($this->service->entries(config_wr6($championship, 2026, 2026)))->toBeEmpty();
});

// ── Vorbelegung (§6.6.1) ─────────────────────────────────────────────────────

it('bevorzugt die Europameisterschaft mit dem spätesten Zeitraumende', function () {
    pointVersion_wr6();
    $em2026 = championship_wr6();

    $paralympics = Championship::query()->create([
        'name' => 'Paralympics 2028', 'type' => Championship::TYPE_PARALYMPICS, 'year' => 2028,
        'qualification_start' => '2027-01-01', 'qualification_end' => '2028-06-30',
    ]);
    ChampionshipStandard::query()->create([
        'championship_id' => $paralympics->id,
        'stroke_type_id' => stroke_wr6()->id,
        'distance' => 100, 'gender' => 'F', 'sport_class' => 'S9', 'mqs_centiseconds' => 6500,
    ]);

    // Für einen 16-Jährigen ist die Paralympics-Norm kein Maßstab, sondern eine Zahl ohne
    // Aussagekraft.
    expect($this->service->defaultReference()?->getKey())->toBe($em2026->getKey());
});

it('nimmt ohne Europameisterschaft die jüngste vorhandene Meisterschaft', function () {
    $wm = Championship::query()->create([
        'name' => 'WM 2027', 'type' => Championship::TYPE_WC, 'year' => 2027,
        'qualification_start' => '2026-01-01', 'qualification_end' => '2027-06-30',
    ]);
    ChampionshipStandard::query()->create([
        'championship_id' => $wm->id,
        'stroke_type_id' => stroke_wr6()->id,
        'distance' => 100, 'gender' => 'F', 'sport_class' => 'S9', 'mqs_centiseconds' => 6800,
    ]);

    expect($this->service->defaultReference()?->getKey())->toBe($wm->getKey());
});

it('übergeht Meisterschaften ohne gepflegte Normen', function () {
    championship_wr6(mqs: null);

    expect($this->service->defaultReference())->toBeNull();
});

// ── Oberfläche und PDF ───────────────────────────────────────────────────────

it('zeigt die Auswertung samt Hinweis allen angemeldeten Nutzern', function () {
    pointVersion_wr6();
    championship_wr6();
    result_wr6(event_wr6(meet_wr6('Kurzbahn', '2026-03-13'), 100), athlete_wr6('Jung', '2009-06-01'), 7100, 700);

    $this->actingAs(User::factory()->create(['is_admin' => false, 'club_id' => Club::query()->value('id')]))
        ->get(route('wps.talent-report'))
        ->assertOk()
        // Der Hinweis nach §6.6.5 ist verpflichtend und erscheint immer.
        ->assertSee('kein Leistungsnachweis');
});

it('liefert das PDF aus', function () {
    pointVersion_wr6();
    $championship = championship_wr6();
    result_wr6(event_wr6(meet_wr6('Kurzbahn', '2026-03-13'), 100), athlete_wr6('Jung', '2009-06-01'), 7100, 700);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('wps.talent-report.pdf').'?reference='.$championship->id.'&from=2026&to=2026')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('verweist ohne Referenznorm zurück auf die Ansicht', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('wps.talent-report.pdf'))
        ->assertRedirect(route('wps.talent-report'));
});

it('belegt Referenznorm und Schwellen vor', function () {
    pointVersion_wr6();
    $championship = championship_wr6();

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsTalentReport::class);

    expect($komponente->instance()->config()?->reference->getKey())->toBe($championship->getKey())
        ->and($komponente->instance()->config()?->youthThreshold)
        ->toBe(WpsTalentReportConfiguration::DEFAULT_YOUTH_THRESHOLD)
        ->and($komponente->instance()->config()?->course)->toBe(WpsRankingFilter::COURSE_SCM);
});

it('zieht das Zeitraumende nach, wenn der Beginn dahinter geschoben wird', function () {
    pointVersion_wr6();
    championship_wr6();
    meet_wr6('Alt', '2024-05-01');
    meet_wr6('Neu', '2026-05-01');

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsTalentReport::class);

    $komponente->call('setInput', 'toYear', '2024');
    $komponente->call('setInput', 'fromYear', '2026');

    // Ein Zeitraum, dessen Ende vor dem Beginn liegt, ergibt keine Auswertung.
    expect($komponente->instance()->config()?->fromYear)->toBe(2026)
        ->and($komponente->instance()->config()?->toYear)->toBe(2026);
});

it('greift bei einem unsinnigen Prozentsatz auf den Vorschlagswert zurück', function () {
    pointVersion_wr6();
    championship_wr6();

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsTalentReport::class);

    $komponente->call('setInput', 'youthThreshold', '150');

    expect($komponente->instance()->config()?->youthThreshold)
        ->toBe(WpsTalentReportConfiguration::DEFAULT_YOUTH_THRESHOLD);
});

// ── Nicht bewertbare Ergebnisse ──────────────────────────────────────────────

it('übergeht Ergebnisse mit null Punkten', function () {
    pointVersion_wr6();
    $championship = championship_wr6();
    $meet = meet_wr6('Kurzbahn', '2026-03-13');

    result_wr6(event_wr6($meet, 100), athlete_wr6('Bewertet', '2009-06-01'), 7100, 700);

    // 0 Punkte heißt "nicht bewertbar" — die Gompertz-Funktion fällt auf null, wenn die Zeit
    // weit außerhalb des Bereichs liegt, für den der Parametersatz gedacht ist. Als Zeile
    // ergäbe das einen Abstand in voller Schwellenhöhe.
    result_wr6(event_wr6($meet, 100), athlete_wr6('Unbewertbar', '2009-06-01'), 25000, 0);

    $zeilen = $this->service->entries(config_wr6($championship, 2026, 2026));

    expect($zeilen)->toHaveCount(1)
        ->and($zeilen->first()->athlete->last_name)->toBe('Bewertet');
});

it('stellt die Bewerbe eines Athleten zusammen', function () {
    pointVersion_wr6();
    pointVersion_wr6(200);

    $championship = championship_wr6();
    ChampionshipStandard::query()->create([
        'championship_id' => $championship->id,
        'stroke_type_id' => stroke_wr6()->id,
        'distance' => 200,
        'gender' => 'F',
        'sport_class' => 'S9',
        'mqs_centiseconds' => 15000,
    ]);

    $meet = meet_wr6('Kurzbahn', '2026-03-13');

    $zwei = athlete_wr6('Zweifach', '2009-06-01');
    result_wr6(event_wr6($meet, 100), $zwei, 7100, 700);
    result_wr6(event_wr6($meet, 200), $zwei, 15500, 640);

    $ein = athlete_wr6('Einfach', '2009-06-01');
    result_wr6(event_wr6($meet, 100), $ein, 7200, 670);

    $gruppe = $this->service->report(config_wr6($championship, 2026, 2026))
        ->get(WpsTalentReportConfiguration::GROUP_YOUTH);

    $namen = $gruppe->map(static fn ($z): string => $z->athlete->last_name)->all();

    // Die Zeilen eines Athleten stehen beieinander, nicht über die Liste verstreut.
    expect($namen)->toBe(['Zweifach', 'Zweifach', 'Einfach']);
});

// ── Wahl der Referenznorm (MQS oder MET) ─────────────────────────────────────

it('rechnet wahlweise gegen MQS oder MET', function () {
    pointVersion_wr6();
    $championship = championship_wr6();
    result_wr6(event_wr6(meet_wr6('Kurzbahn', '2026-03-13'), 100), athlete_wr6('Jung', '2009-06-01'), 7100, 700);

    $mitMqs = new WpsTalentReportConfiguration(
        fromYear: 2026, toYear: 2026, reference: $championship,
        normType: WpsTalentReportConfiguration::NORM_MQS,
    );
    $mitMet = new WpsTalentReportConfiguration(
        fromYear: 2026, toYear: 2026, reference: $championship,
        normType: WpsTalentReportConfiguration::NORM_MET,
    );

    $gegenMqs = $this->service->entries($mitMqs)->sole();
    $gegenMet = $this->service->entries($mitMet)->sole();

    // Die MET ist die langsamere Norm und ergibt damit die niedrigere Punktzahl — und eine
    // Schwelle, die der Nachwuchs eher erreicht.
    expect($gegenMqs->normTime)->toBe(7000)
        ->and($gegenMet->normTime)->toBe(7600)
        ->and($gegenMet->normPoints)->toBeLessThan($gegenMqs->normPoints)
        ->and($gegenMet->thresholdPoints)->toBeLessThan($gegenMqs->thresholdPoints);
});

it('lässt Zeilen ohne MET weg, wenn gegen MET gerechnet wird', function () {
    pointVersion_wr6();
    $championship = championship_wr6(met: null);
    result_wr6(event_wr6(meet_wr6('Kurzbahn', '2026-03-13'), 100), athlete_wr6('Jung', '2009-06-01'), 7100, 700);

    $mitMet = new WpsTalentReportConfiguration(
        fromYear: 2026, toYear: 2026, reference: $championship,
        normType: WpsTalentReportConfiguration::NORM_MET,
    );

    // Nicht jede Meisterschaft führt MET-Zeiten; ohne Bezugsgröße gibt es keine Zeile.
    expect($this->service->entries($mitMet))->toBeEmpty()
        ->and($this->service->entries(config_wr6($championship, 2026, 2026)))->toHaveCount(1);
});

it('nennt die gewählte Norm in der Beschreibung', function () {
    $championship = championship_wr6();

    $mitMet = new WpsTalentReportConfiguration(
        fromYear: 2026, toYear: 2026, reference: $championship,
        normType: WpsTalentReportConfiguration::NORM_MET,
    );

    expect(config_wr6($championship, 2026, 2026)->describe())->toContain('(MQS)')
        ->and($mitMet->describe())->toContain('(MET)')
        ->and($mitMet->normLabel())->toBe('MET');
});

it('schaltet die Norm über die Komponente um', function () {
    pointVersion_wr6();
    championship_wr6();

    $komponente = Livewire::actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->test(WpsTalentReport::class);

    expect($komponente->instance()->config()?->normType)
        ->toBe(WpsTalentReportConfiguration::NORM_MQS);

    $komponente->call('setInput', 'normType', 'met');

    expect($komponente->instance()->config()?->normType)
        ->toBe(WpsTalentReportConfiguration::NORM_MET)
        ->and($komponente->instance()->pdfUrl())->toContain('norm=met');
});

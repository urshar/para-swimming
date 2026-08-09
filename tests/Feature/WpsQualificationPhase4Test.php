<?php

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
use App\Models\WpsScmConversionFactor;
use App\Services\QualificationEvaluationService;
use App\Support\QualificationRow;
use App\Support\QualificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class)->group('wps-qual-p4');

// ── Helper (Suffix _wq4 gegen Namenskollisionen) ─────────────────────────────

function nation_wq4(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function club_wq4(string $name): Club
{
    return Club::query()->create(['name' => $name, 'nation_id' => nation_wq4()->id]);
}

function stroke_wq4(string $lenexCode): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => $lenexCode],
        ['code' => $lenexCode, 'name_de' => $lenexCode, 'name_en' => $lenexCode]
    );
}

function athlete_wq4(Club $club, string $nachname, string $gender): Athlete
{
    return Athlete::query()->create([
        'club_id' => $club->id,
        'nation_id' => nation_wq4()->id,
        'first_name' => 'Test',
        'last_name' => $nachname,
        'birth_date' => '2000-05-01',
        'gender' => $gender,
    ]);
}

function championship_wq4(): Championship
{
    return Championship::query()->create([
        'name' => 'EM 2026',
        'short_name' => 'EM 2026',
        'type' => Championship::TYPE_EC,
        'year' => 2026,
        'course' => Championship::COURSE_LCM,
        'qualification_start' => '2025-01-01',
        'qualification_end' => '2026-07-06',
    ]);
}

function meet_wq4(string $name, string $course, string $datum, bool $approved): Meet
{
    return Meet::query()->create([
        'name' => $name,
        'city' => 'Wien',
        'nation_id' => nation_wq4()->id,
        'course' => $course,
        'start_date' => $datum,
        'wps_approved' => $approved,
    ]);
}

function event_wq4(Meet $meet, string $lenexCode, int $distance, string $gender): SwimEvent
{
    return SwimEvent::query()->create([
        'meet_id' => $meet->id,
        'stroke_type_id' => stroke_wq4($lenexCode)->id,
        'event_number' => 1,
        'gender' => $gender,
        'distance' => $distance,
        'relay_count' => 1,
    ]);
}

function result_wq4(SwimEvent $event, Athlete $athlete, int $zeit, string $sportClass, ?string $status): Result
{
    return Result::query()->create([
        'meet_id' => $event->meet_id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => $zeit,
        'sport_class' => $sportClass,
        'status' => $status,
    ]);
}

function standard_wq4(
    Championship $championship,
    string $lenexCode,
    int $distance,
    string $gender,
    string $sportClass,
    ?int $mqs,
    ?int $met,
    ?int $obsv,
): ChampionshipStandard {
    return ChampionshipStandard::query()->create([
        'championship_id' => $championship->getKey(),
        'stroke_type_id' => stroke_wq4($lenexCode)->id,
        'distance' => $distance,
        'gender' => $gender,
        'sport_class' => $sportClass,
        'mqs_centiseconds' => $mqs,
        'met_centiseconds' => $met,
        'obsv_centiseconds' => $obsv,
        'obsv_percent' => $obsv === null ? null : 2.0,
    ]);
}

/** Findet die Zeile eines Athleten zu einem Bewerb. */
function row_wq4(Collection $ergebnis, string $nachname, string $bewerb): ?QualificationRow
{
    $eintrag = $ergebnis->firstWhere(fn (array $e): bool => $e['athlete']->last_name === $nachname);

    return $eintrag === null
        ? null
        : $eintrag['rows']->firstWhere(
            fn (QualificationRow $z): bool => str_contains($z->eventLabel, $bewerb)
        );
}

beforeEach(function () {
    $this->service = app(QualificationEvaluationService::class);

    foreach ([7, 14] as $nummer) {
        BaseTimeSportClass::query()->firstOrCreate(['code' => "S$nummer"], ['sort_order' => $nummer]);
    }

    $this->championship = championship_wq4();
    $this->club = club_wq4('SV Wien');
});

// ── Status je Bewerb (§7.2) ──────────────────────────────────────────────────

it('bewertet eine reale Langbahnzeit unterhalb der MQS als mqs_met', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, 7800, null);

    $athlet = athlete_wq4($this->club, 'Schnell', 'M');
    $meet = meet_wq4('ÖM Langbahn', 'LCM', '2025-06-01', true);
    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7200, 'S7', null);

    $zeile = row_wq4($this->service->evaluate($this->championship, null, null), 'Schnell', '100 m');

    expect($zeile->status->status)->toBe(QualificationStatus::MQS_MET)
        ->and($zeile->status->isProof())->toBeTrue()
        ->and($zeile->status->gapToMqs)->toBe(-119);
});

it('bewertet eine Zeit unterhalb der ÖBSV-Norm als obsv_met', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, 7800, 7172);

    $athlet = athlete_wq4($this->club, 'Sehrschnell', 'M');
    $meet = meet_wq4('ÖM Langbahn', 'LCM', '2025-06-01', true);
    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7100, 'S7', null);

    $zeile = row_wq4($this->service->evaluate($this->championship, null, null), 'Sehrschnell', '100 m');

    expect($zeile->status->status)->toBe(QualificationStatus::OBSV_MET)
        ->and($zeile->status->isProof())->toBeTrue();
});

it('bewertet eine umgerechnete Kurzbahnzeit nie als mqs_met', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    WpsScmConversionFactor::query()->create([
        'stroke_type_id' => stroke_wq4('FREE')->id,
        'factor' => 1.0345,
        'source' => WpsScmConversionFactor::SOURCE_MANUAL,
        'active' => true,
    ]);

    $athlet = athlete_wq4($this->club, 'Kurzbahn', 'M');
    $meet = meet_wq4('Kurzbahn-Meeting', 'SCM', '2025-06-01', true);
    // 7000 × 1,0345 = 7241 — schneller als die MQS, aber umgerechnet.
    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7000, 'S7', null);

    $zeile = row_wq4($this->service->evaluate($this->championship, null, null), 'Kurzbahn', '100 m');

    expect($zeile->status->status)->toBe(QualificationStatus::ESTIMATED_MQS)
        ->and($zeile->status->isEstimate())->toBeTrue()
        ->and($zeile->status->isProof())->toBeFalse()
        ->and($zeile->status->estimatedLcmTime)->toBe(7241);
});

it('lässt eine reale Zeit die umgerechnete schlagen, auch wenn die umgerechnete schneller ist', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    WpsScmConversionFactor::query()->create([
        'stroke_type_id' => stroke_wq4('FREE')->id,
        'factor' => 1.0345,
        'source' => WpsScmConversionFactor::SOURCE_MANUAL,
        'active' => true,
    ]);

    $athlet = athlete_wq4($this->club, 'Beides', 'M');

    // Reale Langbahnzeit: langsamer als die MQS.
    $lcm = meet_wq4('Langbahn', 'LCM', '2025-06-01', true);
    result_wq4(event_wq4($lcm, 'FREE', 100, 'M'), $athlet, 7400, 'S7', null);

    // Umgerechnete Kurzbahnzeit: schneller als die MQS.
    $scm = meet_wq4('Kurzbahn', 'SCM', '2025-07-01', true);
    result_wq4(event_wq4($scm, 'FREE', 100, 'M'), $athlet, 7000, 'S7', null);

    $zeile = row_wq4($this->service->evaluate($this->championship, null, null), 'Beides', '100 m');

    // Die reale Zeit ist maßgeblich — sonst würde aus einem Nichterreichen eine Schätzung,
    // die besser aussieht als die Wirklichkeit.
    expect($zeile->status->status)->toBe(QualificationStatus::NOT_MET)
        ->and($zeile->status->swimTime)->toBe(7400);
});

it('liefert für einen Bewerb ohne Norm no_standard statt not_met', function () {
    $athlet = athlete_wq4($this->club, 'OhneNorm', 'M');
    $meet = meet_wq4('Langbahn', 'LCM', '2025-06-01', true);
    result_wq4(event_wq4($meet, 'FLY', 200, 'M'), $athlet, 30000, 'S7', null);

    $zeile = row_wq4($this->service->evaluate($this->championship, null, null), 'OhneNorm', '200 m');

    expect($zeile->status->status)->toBe(QualificationStatus::NO_STANDARD)
        ->and($zeile->status->hasStandard())->toBeFalse()
        ->and($zeile->status->note)->toContain('keine Norm');
});

it('weist den Abstand zur Norm auch bei Nichterfüllung aus', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    $athlet = athlete_wq4($this->club, 'Knapp', 'M');
    $meet = meet_wq4('Langbahn', 'LCM', '2025-06-01', true);
    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7330, 'S7', null);

    $zeile = row_wq4($this->service->evaluate($this->championship, null, null), 'Knapp', '100 m');

    expect($zeile->status->status)->toBe(QualificationStatus::NOT_MET)
        ->and($zeile->status->gapToMqs)->toBe(11)
        ->and($zeile->status->formattedGap())->toBe('+0,11 s');
});

// ── Bedingte MET-Auswertung (§7.2, Q-R5) ─────────────────────────────────────

it('wertet MET ohne MQS in einem anderen Bewerb als wirkungslos', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, 7800, null);

    $athlet = athlete_wq4($this->club, 'NurMet', 'M');
    $meet = meet_wq4('Langbahn', 'LCM', '2025-06-01', true);
    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7700, 'S7', null);

    $zeile = row_wq4($this->service->evaluate($this->championship, null, null), 'NurMet', '100 m');

    expect($zeile->status->status)->toBe(QualificationStatus::MET_ONLY)
        ->and($zeile->metUsable)->toBeFalse()
        ->and($zeile->status->isProof())->toBeFalse();
});

it('wertet MET als verwertbar, wenn in einem anderen Bewerb die MQS erfüllt ist', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, 7800, null);
    standard_wq4($this->championship, 'BACK', 100, 'M', 'S7', 8500, null, null);

    $athlet = athlete_wq4($this->club, 'MetUndMqs', 'M');
    $meet = meet_wq4('Langbahn', 'LCM', '2025-06-01', true);

    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7700, 'S7', null);
    result_wq4(event_wq4($meet, 'BACK', 100, 'M'), $athlet, 8400, 'S7', null);

    $eintrag = $this->service->evaluate($this->championship, null, null)->first();

    $metZeile = $eintrag['rows']->firstWhere(
        fn (QualificationRow $z): bool => $z->status->status === QualificationStatus::MET_ONLY
    );

    expect($metZeile->metUsable)->toBeTrue();
});

// ── Ergebnisauswahl (§7.1) ───────────────────────────────────────────────────

it('berücksichtigt Ergebnisse außerhalb des Qualifikationszeitraums nicht', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    $athlet = athlete_wq4($this->club, 'ZuFrueh', 'M');
    $davor = meet_wq4('Zu früh', 'LCM', '2024-12-31', true);
    result_wq4(event_wq4($davor, 'FREE', 100, 'M'), $athlet, 7000, 'S7', null);

    expect($this->service->evaluate($this->championship, null, null))->toBeEmpty();
});

it('berücksichtigt den letzten Tag des Zeitraums', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    $athlet = athlete_wq4($this->club, 'Grenztag', 'M');
    $meet = meet_wq4('Letzter Tag', 'LCM', '2026-07-06', true);
    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7000, 'S7', null);

    expect($this->service->evaluate($this->championship, null, null))->toHaveCount(1);
});

it('schließt DNS, DNF, DSQ, SICK und WDR aus, EXH aber nicht', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    $meet = meet_wq4('Langbahn', 'LCM', '2025-06-01', true);
    $event = event_wq4($meet, 'FREE', 100, 'M');

    foreach (['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'] as $index => $status) {
        result_wq4($event, athlete_wq4($this->club, "Aus$index", 'M'), 7000, 'S7', $status);
    }

    $exh = athlete_wq4($this->club, 'AusserKonkurrenz', 'M');
    result_wq4($event, $exh, 7000, 'S7', 'EXH');

    $ergebnis = $this->service->evaluate($this->championship, null, null);

    expect($ergebnis)->toHaveCount(1)
        ->and($ergebnis->first()['athlete']->last_name)->toBe('AusserKonkurrenz')
        ->and($ergebnis->first()['rows']->first()->status->exhibition)->toBeTrue();
});

it('bildet die Sportklasse 21 auf 14 ab', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S14', 7319, null, null);

    $athlet = athlete_wq4($this->club, 'Klasse21', 'M');
    $meet = meet_wq4('Langbahn', 'LCM', '2025-06-01', true);
    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7200, 'S21', null);

    $zeile = row_wq4($this->service->evaluate($this->championship, null, null), 'Klasse21', '100 m');

    expect($zeile->status->status)->toBe(QualificationStatus::MQS_MET)
        // results.sport_class bleibt unverändert — die Zuordnung gilt nur für WPS-Zwecke.
        ->and($zeile->sportClass)->toBe('S21')
        ->and($zeile->wpsSportClass)->toBe('S14');
});

it('rechnet ohne hinterlegten Faktor nicht um und begründet das', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    $athlet = athlete_wq4($this->club, 'OhneFaktor', 'M');
    $meet = meet_wq4('Kurzbahn', 'SCM', '2025-06-01', true);
    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7000, 'S7', null);

    $zeile = row_wq4($this->service->evaluate($this->championship, null, null), 'OhneFaktor', '100 m');

    // Ein fehlender Faktor darf nicht als 1 behandelt werden.
    expect($zeile->status->status)->toBe(QualificationStatus::NOT_MET)
        ->and($zeile->status->estimatedLcmTime)->toBeNull()
        ->and($zeile->status->note)->toContain('kein Umrechnungsfaktor');
});

// ── Qualifikantenliste (Frage A) ─────────────────────────────────────────────

it('nimmt nur Nachweise in die Qualifikantenliste auf', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, 7800, null);

    WpsScmConversionFactor::query()->create([
        'stroke_type_id' => stroke_wq4('FREE')->id,
        'factor' => 1.0345,
        'source' => WpsScmConversionFactor::SOURCE_MANUAL,
        'active' => true,
    ]);

    $lcm = meet_wq4('Langbahn anerkannt', 'LCM', '2025-06-01', true);
    $scm = meet_wq4('Kurzbahn anerkannt', 'SCM', '2025-06-02', true);

    $nachweis = athlete_wq4($this->club, 'Nachweis', 'M');
    result_wq4(event_wq4($lcm, 'FREE', 100, 'M'), $nachweis, 7200, 'S7', null);

    $geschaetzt = athlete_wq4($this->club, 'Geschaetzt', 'M');
    result_wq4(event_wq4($scm, 'FREE', 100, 'M'), $geschaetzt, 7000, 'S7', null);

    $nurMet = athlete_wq4($this->club, 'NurMet', 'M');
    result_wq4(event_wq4($lcm, 'FREE', 100, 'M'), $nurMet, 7700, 'S7', null);

    $liste = $this->service->qualified($this->championship, null);

    expect($liste)->toHaveCount(1)
        ->and($liste->first()->athlete->last_name)->toBe('Nachweis');
});

it('schließt Ergebnisse aus nicht WPS-anerkannten Wettkämpfen aus und weist sie aus', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    $athlet = athlete_wq4($this->club, 'NichtAnerkannt', 'M');
    $meet = meet_wq4('Vereinsmeeting', 'LCM', '2025-06-01', false);
    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7200, 'S7', null);

    $liste = $this->service->qualified($this->championship, null);
    $ausgeschlossen = $this->service->excludedForMissingApproval($this->championship, null);

    expect($liste)->toBeEmpty()
        ->and($ausgeschlossen)->toHaveCount(1)
        ->and($ausgeschlossen->first()->athlete->last_name)->toBe('NichtAnerkannt');
});

it('zeigt eine nicht anerkannte Zeit in der Förderansicht sehr wohl', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    $athlet = athlete_wq4($this->club, 'NichtAnerkannt', 'M');
    $meet = meet_wq4('Vereinsmeeting', 'LCM', '2025-06-01', false);
    result_wq4(event_wq4($meet, 'FREE', 100, 'M'), $athlet, 7200, 'S7', null);

    $zeile = row_wq4($this->service->evaluate($this->championship, null, null), 'NichtAnerkannt', '100 m');

    expect($zeile->status->status)->toBe(QualificationStatus::MQS_MET)
        ->and($zeile->status->meetApproved)->toBeFalse()
        // Die Norm ist erfüllt, der Nachweis fehlt trotzdem.
        ->and($zeile->status->isProof())->toBeFalse();
});

// ── Sichtbarkeit und Routen ──────────────────────────────────────────────────

it('zeigt Vereinsnutzern nur die Athleten ihres Vereins', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    $fremd = club_wq4('SV Graz');
    $meet = meet_wq4('Langbahn', 'LCM', '2025-06-01', true);
    $event = event_wq4($meet, 'FREE', 100, 'M');

    result_wq4($event, athlete_wq4($this->club, 'Eigen', 'M'), 7200, 'S7', null);
    result_wq4($event, athlete_wq4($fremd, 'Fremd', 'M'), 7100, 'S7', null);

    $eigene = $this->service->evaluate($this->championship, $this->club->id, null);

    expect($eigene)->toHaveCount(1)
        ->and($eigene->first()['athlete']->last_name)->toBe('Eigen');
});

it('erlaubt Vereinsnutzern beide Ansichten und beschränkt sie auf den eigenen Verein', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    $fremd = club_wq4('SV Graz');
    $meet = meet_wq4('Langbahn', 'LCM', '2025-06-01', true);
    $event = event_wq4($meet, 'FREE', 100, 'M');

    result_wq4($event, athlete_wq4($this->club, 'Eigen', 'M'), 7200, 'S7', null);
    result_wq4($event, athlete_wq4($fremd, 'Fremd', 'M'), 7100, 'S7', null);

    $nutzer = User::factory()->create(['is_admin' => false, 'club_id' => $this->club->id]);

    $this->actingAs($nutzer)
        ->get(route('championships.qualified', $this->championship))
        ->assertOk()
        ->assertSee('Eigen')
        ->assertDontSee('Fremd');

    $this->actingAs($nutzer)
        ->get(route('championships.development', $this->championship))
        ->assertOk()
        ->assertSee('Eigen')
        ->assertDontSee('Fremd');
});

it('zeigt Admins alle Vereine', function () {
    standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    $fremd = club_wq4('SV Graz');
    $meet = meet_wq4('Langbahn', 'LCM', '2025-06-01', true);
    $event = event_wq4($meet, 'FREE', 100, 'M');

    result_wq4($event, athlete_wq4($this->club, 'Eigen', 'M'), 7200, 'S7', null);
    result_wq4($event, athlete_wq4($fremd, 'Fremd', 'M'), 7100, 'S7', null);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('championships.qualified', $this->championship))
        ->assertOk()
        ->assertSee('Eigen')
        ->assertSee('Fremd');
});

// ── Zielzeit auf der abweichenden Bahnlänge (§6) ─────────────────────────────

it('errechnet die Zielzeit auf der Kurzbahn aus der Norm', function () {
    $norm = standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, 7172);

    WpsScmConversionFactor::query()->create([
        'stroke_type_id' => stroke_wq4('FREE')->id,
        'factor' => 1.0345,
        'source' => WpsScmConversionFactor::SOURCE_MANUAL,
        'active' => true,
    ]);

    // 7172 ÷ 1,0345 = 6932,8 → floor → 6932
    expect($this->service->targetTimeOnOtherCourse($norm, 7172))->toBe(6932);
});

it('liefert ohne Faktor keine Zielzeit statt der ungerechneten Norm', function () {
    $norm = standard_wq4($this->championship, 'FREE', 100, 'M', 'S7', 7319, null, null);

    expect($this->service->targetTimeOnOtherCourse($norm, 7319))->toBeNull();
});

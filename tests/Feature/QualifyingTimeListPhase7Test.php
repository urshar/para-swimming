<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Qualification;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeClubUser_qtl8(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_qtl8(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], [
        'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
    ]);
}

function makeClub_qtl8(string $name = 'Verein A'): Club
{
    static $i = 0;
    $i++;

    return Club::create(['name' => $name, 'code' => 'C'.$i, 'nation_id' => makeNation_qtl8()->id]);
}

function makeAthlete_qtl8(Club $club, string $firstName = 'Max', string $lastName = 'Muster'): Athlete
{
    return Athlete::create([
        'first_name' => $firstName, 'last_name' => $lastName, 'gender' => 'M',
        'nation_id' => makeNation_qtl8()->id, 'club_id' => $club->id,
    ]);
}

function makeStrokeType_qtl8(string $lenexCode = 'FREE'): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $lenexCode], [
        'name_de' => $lenexCode, 'name_en' => $lenexCode, 'code' => strtolower($lenexCode),
    ]);
}

function makeQualifyingList_qtl8(int $year = 2027, string $periodStart = '2026-05-12', string $periodEnd = '2027-05-29'): QualifyingTimeList
{
    return QualifyingTimeList::create([
        'year' => $year, 'is_active' => true,
        'qualification_period_start' => $periodStart, 'qualification_period_end' => $periodEnd,
    ]);
}

function makeMeet_qtl8(string $startDate = '2026-08-01'): Meet
{
    static $k = 0;
    $k++;

    return Meet::create([
        'name' => 'Meet '.$k, 'nation_id' => makeNation_qtl8()->id, 'course' => 'LCM',
        'start_date' => $startDate,
    ]);
}

function makeSwimEvent_qtl8(Meet $meet, StrokeType $stroke, int $distance = 100): SwimEvent
{
    return SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => $distance,
        'relay_count' => 1, 'gender' => 'M',
    ]);
}

function makeQualifyingTime_qtl8(
    QualifyingTimeList $list, StrokeType $stroke, int $distance, string $gender, string $sportClass, int $value
): QualifyingTime {
    return QualifyingTime::create([
        'qualifying_time_list_id' => $list->id, 'stroke_type_id' => $stroke->id, 'distance' => $distance,
        'gender' => $gender, 'sport_class' => $sportClass, 'value_centiseconds' => $value,
        'source' => QualifyingTime::SOURCE_CALCULATED,
    ]);
}

/** Legt eine vollständige Qualifikation für einen Athleten an (ohne über calculate() zu gehen). */
function makeQualification_qtl8(QualifyingTimeList $list, Club $club, Athlete $athlete, QualifyingTime $qualifyingTime, int $swimTime): Qualification
{
    return Qualification::create([
        'qualifying_time_list_id' => $list->id,
        'qualifying_time_id' => $qualifyingTime->id,
        'athlete_id' => $athlete->id,
        'result_id' => Result::create([
            'meet_id' => makeMeet_qtl8()->id,
            'swim_event_id' => makeSwimEvent_qtl8(makeMeet_qtl8(), makeStrokeType_qtl8())->id,
            'athlete_id' => $athlete->id, 'club_id' => $club->id,
            'swim_time' => $swimTime, 'sport_class' => $qualifyingTime->sport_class, 'points' => 500,
        ])->id,
        'club_id' => $club->id,
        'sport_class' => $qualifyingTime->sport_class,
        'swim_time_centiseconds' => $swimTime,
        'points' => 500,
        'qualified_at' => '2026-08-01',
    ]);
}

// ── Richtzeiten-PDF ────────────────────────────────────────────────────────────

describe('QualifyingTimeListController::pdfTimes', function () {
    it('liefert ein PDF für alle authentifizierten User', function () {
        $list = makeQualifyingList_qtl8();
        $stroke = makeStrokeType_qtl8();
        makeQualifyingTime_qtl8($list, $stroke, 100, 'M', 'S9', 6000);

        $response = $this->actingAs(makeClubUser_qtl8())->get(route('qualifying-time-lists.pdf', $list));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
    })->group('qualifying-time-lists-p7');

    it('funktioniert auch für eine historisierte (nicht-aktuellste) Liste', function () {
        $old = makeQualifyingList_qtl8(2026, '2025-05-12', '2026-05-29');
        makeQualifyingList_qtl8();

        $this->actingAs(makeClubUser_qtl8())
            ->get(route('qualifying-time-lists.pdf', $old))
            ->assertOk();
    })->group('qualifying-time-lists-p7');
});

// ── Qualifikations-PDF ───────────────────────────────────────────────────────────

describe('QualifyingTimeListController::pdfQualifications', function () {
    it('liefert ein PDF für alle authentifizierten User', function () {
        $list = makeQualifyingList_qtl8();
        $stroke = makeStrokeType_qtl8();
        $club = makeClub_qtl8();
        $athlete = makeAthlete_qtl8($club);
        $qt = makeQualifyingTime_qtl8($list, $stroke, 100, 'M', 'S9', 6000);
        makeQualification_qtl8($list, $club, $athlete, $qt, 5900);

        $response = $this->actingAs(makeClubUser_qtl8())
            ->get(route('qualifying-time-lists.qualifications.pdf', $list));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
    })->group('qualifying-time-lists-p7');

    it('funktioniert auch für eine historisierte Liste', function () {
        $old = makeQualifyingList_qtl8(2026, '2025-05-12', '2026-05-29');
        makeQualifyingList_qtl8();

        $this->actingAs(makeClubUser_qtl8())
            ->get(route('qualifying-time-lists.qualifications.pdf', $old))
            ->assertOk();
    })->group('qualifying-time-lists-p7');
});

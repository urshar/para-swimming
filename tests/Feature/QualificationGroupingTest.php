<?php

use App\Models\Athlete;
use App\Models\Club;
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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeClubUser_qtl9(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_qtl9(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], [
        'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
    ]);
}

function makeClub_qtl9(): Club
{
    static $i = 0;
    $i++;

    return Club::create(['name' => 'Verein '.$i, 'code' => 'C'.$i, 'nation_id' => makeNation_qtl9()->id]);
}

function makeAthlete_qtl9(Club $club, string $firstName, string $lastName): Athlete
{
    return Athlete::create([
        'first_name' => $firstName, 'last_name' => $lastName, 'gender' => 'M',
        'nation_id' => makeNation_qtl9()->id, 'club_id' => $club->id,
    ]);
}

function makeStrokeType_qtl9(string $lenexCode): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $lenexCode], [
        'name_de' => $lenexCode, 'name_en' => $lenexCode, 'code' => strtolower($lenexCode),
    ]);
}

function makeGroup_qtl9(string $code, int $sortOrder): SportClassGroup
{
    return SportClassGroup::create(['code' => $code, 'name_de' => $code, 'sort_order' => $sortOrder]);
}

function makeQualifyingList_qtl9(): QualifyingTimeList
{
    return QualifyingTimeList::create([
        'year' => 2027, 'is_active' => true,
        'qualification_period_start' => '2026-05-12', 'qualification_period_end' => '2027-05-29',
    ]);
}

function makeQualifyingTime_qtl9(QualifyingTimeList $list, StrokeType $stroke, string $sportClass): QualifyingTime
{
    return QualifyingTime::create([
        'qualifying_time_list_id' => $list->id, 'stroke_type_id' => $stroke->id, 'distance' => 100,
        'gender' => 'M', 'sport_class' => $sportClass, 'value_centiseconds' => 6000,
        'source' => QualifyingTime::SOURCE_CALCULATED,
    ]);
}

function makeQualification_qtl9(
    QualifyingTimeList $list, Club $club, Athlete $athlete, QualifyingTime $qualifyingTime
): Qualification {
    $meet = Meet::create([
        'name' => 'Qualimeet', 'nation_id' => makeNation_qtl9()->id, 'course' => 'LCM', 'start_date' => '2026-08-01',
    ]);
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => $qualifyingTime->stroke_type_id, 'distance' => 100,
        'relay_count' => 1, 'gender' => 'M',
    ]);
    $result = Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'swim_time' => 5900, 'sport_class' => $qualifyingTime->sport_class, 'points' => 500,
    ]);

    return Qualification::create([
        'qualifying_time_list_id' => $list->id,
        'qualifying_time_id' => $qualifyingTime->id,
        'athlete_id' => $athlete->id,
        'result_id' => $result->id,
        'club_id' => $club->id,
        'sport_class' => $qualifyingTime->sport_class,
        'swim_time_centiseconds' => 5900,
        'points' => 500,
        'qualified_at' => '2026-08-01',
    ]);
}

// ── Gliederung nach Behinderungsgruppe und Lage ──────────────────────────────────

describe('Qualifikationsliste — Gliederung nach Behinderungsgruppe und Lage', function () {
    it('gliedert die Anzeige zuerst nach Behinderungsgruppe, dann nach Lage', function () {
        $list = makeQualifyingList_qtl9();
        $free = makeStrokeType_qtl9('FREE');
        $back = makeStrokeType_qtl9('BACK');
        $groupPI = makeGroup_qtl9('PI', 1);
        $groupVI = makeGroup_qtl9('VI', 2);
        SportClassGroupMember::create(['sport_class_group_id' => $groupPI->id, 'sport_class' => 'S9']);
        SportClassGroupMember::create(['sport_class_group_id' => $groupVI->id, 'sport_class' => 'S12']);

        $club = makeClub_qtl9();
        $qtFreeS9 = makeQualifyingTime_qtl9($list, $free, 'S9');
        $qtBackS9 = makeQualifyingTime_qtl9($list, $back, 'S9');
        $qtFreeS12 = makeQualifyingTime_qtl9($list, $free, 'S12');

        makeQualification_qtl9($list, $club, makeAthlete_qtl9($club, 'Anna', 'Frei9'), $qtFreeS9);
        makeQualification_qtl9($list, $club, makeAthlete_qtl9($club, 'Bea', 'Rueck9'), $qtBackS9);
        makeQualification_qtl9($list, $club, makeAthlete_qtl9($club, 'Cara', 'Frei12'), $qtFreeS12);

        $response = $this->actingAs(makeClubUser_qtl9())
            ->get(route('qualifying-time-lists.qualifications', $list));

        $response->assertOk();

        // Behinderungsgruppen in sort_order-Reihenfolge (PI vor VI)
        $response->assertSeeInOrder(['PI', 'VI']);

        // Innerhalb von PI: Freistil vor Rücken (Lagen-Reihenfolge) — beide
        // Strokes gehören zur PI-Gruppe (S9), erscheinen also zwischen PI und VI.
        $response->assertSeeInOrder(['PI', 'FREE', 'BACK', 'VI']);
    })->group('qualifying-time-lists-grouping');

    it('zeigt nicht zugeordnete Sportklassen unter „Sonstige Sportklassen"', function () {
        $list = makeQualifyingList_qtl9();
        $free = makeStrokeType_qtl9('FREE');
        // Bewusst KEINE SportClassGroupMember für S99 angelegt
        $club = makeClub_qtl9();
        $qt = makeQualifyingTime_qtl9($list, $free, 'S99');
        makeQualification_qtl9($list, $club, makeAthlete_qtl9($club, 'Dora', 'Ohnegruppe'), $qt);

        $this->actingAs(makeClubUser_qtl9())
            ->get(route('qualifying-time-lists.qualifications', $list))
            ->assertOk()
            ->assertSee('Sonstige Sportklassen')
            ->assertSee('Ohnegruppe');
    })->group('qualifying-time-lists-grouping');

    it('behält die Filter zusätzlich zur Gliederung bei', function () {
        $list = makeQualifyingList_qtl9();
        $free = makeStrokeType_qtl9('FREE');
        $group = makeGroup_qtl9('PI', 1);
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S2']);

        $club = makeClub_qtl9();
        $qtS9 = makeQualifyingTime_qtl9($list, $free, 'S9');
        $qtS2 = makeQualifyingTime_qtl9($list, $free, 'S2');
        makeQualification_qtl9($list, $club, makeAthlete_qtl9($club, 'Eva', 'Neun'), $qtS9);
        makeQualification_qtl9($list, $club, makeAthlete_qtl9($club, 'Fiona', 'Zwei'), $qtS2);

        $this->actingAs(makeClubUser_qtl9())
            ->get(route('qualifying-time-lists.qualifications', $list).'?sport_class=S9')
            ->assertOk()
            ->assertSee('Neun')
            ->assertDontSee('Zwei');
    })->group('qualifying-time-lists-grouping');

    it('gliedert innerhalb einer Lage zusätzlich nach Distanz und zeigt beide Qualifikationen', function () {
        $list = makeQualifyingList_qtl9();
        $free = makeStrokeType_qtl9('FREE');
        $group = makeGroup_qtl9('PI', 1);
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);

        $club = makeClub_qtl9();
        $athlete = makeAthlete_qtl9($club, 'Gina', 'Doppelt');

        $qt50 = QualifyingTime::create([
            'qualifying_time_list_id' => $list->id, 'stroke_type_id' => $free->id, 'distance' => 50,
            'gender' => 'M', 'sport_class' => 'S9', 'value_centiseconds' => 3000,
            'source' => QualifyingTime::SOURCE_CALCULATED,
        ]);
        $qt100 = makeQualifyingTime_qtl9($list, $free, 'S9');

        $meet = Meet::create([
            'name' => 'Qualimeet', 'nation_id' => makeNation_qtl9()->id, 'course' => 'LCM', 'start_date' => '2026-08-01',
        ]);

        $event50 = SwimEvent::create([
            'meet_id' => $meet->id, 'stroke_type_id' => $free->id, 'distance' => 50,
            'relay_count' => 1, 'gender' => 'M',
        ]);
        $result50 = Result::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event50->id, 'athlete_id' => $athlete->id,
            'club_id' => $club->id, 'swim_time' => 2900, 'sport_class' => 'S9', 'points' => 480,
        ]);
        Qualification::create([
            'qualifying_time_list_id' => $list->id, 'qualifying_time_id' => $qt50->id,
            'athlete_id' => $athlete->id, 'result_id' => $result50->id, 'club_id' => $club->id,
            'sport_class' => 'S9', 'swim_time_centiseconds' => 2900, 'points' => 480, 'qualified_at' => '2026-08-01',
        ]);

        $event100 = SwimEvent::create([
            'meet_id' => $meet->id, 'stroke_type_id' => $free->id, 'distance' => 100,
            'relay_count' => 1, 'gender' => 'M',
        ]);
        $result100 = Result::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event100->id, 'athlete_id' => $athlete->id,
            'club_id' => $club->id, 'swim_time' => 5900, 'sport_class' => 'S9', 'points' => 520,
        ]);
        Qualification::create([
            'qualifying_time_list_id' => $list->id, 'qualifying_time_id' => $qt100->id,
            'athlete_id' => $athlete->id, 'result_id' => $result100->id, 'club_id' => $club->id,
            'sport_class' => 'S9', 'swim_time_centiseconds' => 5900, 'points' => 520, 'qualified_at' => '2026-08-01',
        ]);

        $response = $this->actingAs(makeClubUser_qtl9())
            ->get(route('qualifying-time-lists.qualifications', $list));

        $response->assertOk()
            ->assertSee('50m FREE')
            ->assertSee('100m FREE')
            ->assertSee('480') // Punkte 50m
            ->assertSee('520'); // Punkte 100m -> beide Zeilen bleiben erhalten

        // 50m-Abschnitt steht vor dem 100m-Abschnitt (aufsteigend nach Distanz)
        $response->assertSeeInOrder(['50m FREE', '100m FREE']);
    })->group('qualifying-time-lists-grouping');
});

// ── Inhaltsverzeichnis / Sprungmarken (Erik, 2026-07-20) ─────────────────────────

describe('Inhaltsverzeichnis auf der Qualifikationsliste', function () {
    it('zeigt einen Sprunglink pro Behinderungsgruppe', function () {
        $list = makeQualifyingList_qtl9();
        $free = makeStrokeType_qtl9('FREE');
        $group = makeGroup_qtl9('PI', 1);
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);

        $club = makeClub_qtl9();
        $qt = makeQualifyingTime_qtl9($list, $free, 100, 'M', 'S9', 6000);
        $meet = Meet::create([
            'name' => 'Meet', 'nation_id' => makeNation_qtl9()->id, 'course' => 'LCM', 'start_date' => '2026-08-01',
        ]);
        $event = SwimEvent::create([
            'meet_id' => $meet->id, 'stroke_type_id' => $free->id, 'distance' => 100, 'relay_count' => 1, 'gender' => 'M',
        ]);
        $athlete = makeAthlete_qtl9($club, 'Anna', 'Frei9');
        $result = Result::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
            'club_id' => $club->id, 'swim_time' => 5900, 'sport_class' => 'S9', 'points' => 500,
        ]);
        Qualification::create([
            'qualifying_time_list_id' => $list->id, 'qualifying_time_id' => $qt->id, 'athlete_id' => $athlete->id,
            'result_id' => $result->id, 'club_id' => $club->id, 'sport_class' => 'S9',
            'swim_time_centiseconds' => 5900, 'points' => 500, 'qualified_at' => '2026-08-01',
        ]);

        $this->actingAs(makeClubUser_qtl9())
            ->get(route('qualifying-time-lists.qualifications', $list))
            ->assertOk()
            ->assertSee('Inhaltsverzeichnis')
            ->assertSee('href="#group-'.$group->id.'"', false);
    })->group('qualifying-time-lists-grouping');
});

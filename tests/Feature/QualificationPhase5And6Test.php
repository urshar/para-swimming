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

function makeAdmin_qtl7(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_qtl7(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_qtl7(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], [
        'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
    ]);
}

function makeClub_qtl7(string $name): Club
{
    static $i = 0;
    $i++;

    return Club::create([
        'name' => $name, 'code' => 'C'.$i,
        'nation_id' => makeNation_qtl7()->id,
    ]);
}

function makeAthlete_qtl7(Club $club, string $firstName, string $lastName, string $gender = 'M'): Athlete
{
    return Athlete::create([
        'first_name' => $firstName, 'last_name' => $lastName, 'gender' => $gender,
        'nation_id' => makeNation_qtl7()->id, 'club_id' => $club->id,
    ]);
}

function makeStrokeType_qtl7(string $lenexCode = 'FREE'): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $lenexCode], [
        'name_de' => $lenexCode, 'name_en' => $lenexCode, 'code' => strtolower($lenexCode),
    ]);
}

function makeQualifyingList_qtl7(int $year, string $periodStart, string $periodEnd): QualifyingTimeList
{
    return QualifyingTimeList::create([
        'year' => $year, 'is_active' => true,
        'qualification_period_start' => $periodStart, 'qualification_period_end' => $periodEnd,
    ]);
}

function makeMeet_qtl7(string $startDate): Meet
{
    static $k = 0;
    $k++;

    return Meet::create([
        'name' => 'Meet '.$k, 'nation_id' => makeNation_qtl7()->id, 'course' => 'LCM',
        'start_date' => $startDate,
    ]);
}

function makeSwimEvent_qtl7(Meet $meet, StrokeType $stroke, int $distance = 100): SwimEvent
{
    return SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => $distance,
        'relay_count' => 1, 'gender' => 'M',
    ]);
}

function makeResult_qtl7(
    Meet $meet,
    SwimEvent $event,
    Athlete $athlete,
    Club $club,
    int $swimTime,
    string $sportClass = 'S9',
): Result {
    static $l = 0;
    $l++;

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'swim_time' => $swimTime, 'sport_class' => $sportClass,
        'points' => 500, 'lane' => $l,
    ]);
}

function makeQualifyingTime_qtl7(
    QualifyingTimeList $list,
    StrokeType $stroke,
    int $distance,
    string $gender,
    string $sportClass,
    int $value
): QualifyingTime {
    return QualifyingTime::create([
        'qualifying_time_list_id' => $list->id, 'stroke_type_id' => $stroke->id, 'distance' => $distance,
        'gender' => $gender, 'sport_class' => $sportClass, 'value_centiseconds' => $value,
        'source' => QualifyingTime::SOURCE_CALCULATED,
    ]);
}

/** Baut eine vollständige, ermittelte Qualifikation für einen Athleten auf. */
function qualifyAthlete_qtl7(
    QualifyingTimeList $list,
    StrokeType $stroke,
    Club $club,
    Athlete $athlete,
    int $swimTime,
    int $qualifyingTimeValue = 6000,
): void {
    makeQualifyingTime_qtl7($list, $stroke, 100, 'M', 'S9', $qualifyingTimeValue);
    $meet = makeMeet_qtl7($list->qualification_period_start->copy()->addDays(10)->toDateString());
    makeResult_qtl7($meet, makeSwimEvent_qtl7($meet, $stroke), $athlete, $club, $swimTime);
}

// ── Phase 6: Anzeige & Filter ──────────────────────────────────────────────────

describe('QualifyingTimeListController::qualifications — Zugriff', function () {
    it('Club-User hat Lesezugriff (kein Admin-Gate)', function () {
        $list = makeQualifyingList_qtl7(2027, '2026-05-12', '2027-05-29');

        $this->actingAs(makeClubUser_qtl7())
            ->get(route('qualifying-time-lists.qualifications', $list))
            ->assertOk();
    })->group('qualifying-time-lists-p5-p6');
});

describe('QualifyingTimeListController::qualifications — Anzeige', function () {
    it('zeigt die eingefrorenen Snapshot-Werte korrekt an', function () {
        $list = makeQualifyingList_qtl7(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl7();
        $club = makeClub_qtl7('Schwimmverein Wien');
        $athlete = makeAthlete_qtl7($club, 'Anna', 'Musterfrau');
        qualifyAthlete_qtl7($list, $stroke, $club, $athlete, 5900);

        $this->actingAs(makeAdmin_qtl7())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list));

        $response = $this->actingAs(makeClubUser_qtl7())
            ->get(route('qualifying-time-lists.qualifications', $list));

        $response->assertOk()
            ->assertSee('Musterfrau')
            ->assertSee('Anna')
            ->assertSee('Schwimmverein Wien')
            ->assertSee('S9');
    })->group('qualifying-time-lists-p5-p6');

    it('ändert die Anzeige nicht, wenn der Athlet später den Verein wechselt (Snapshot-Prinzip)', function () {
        $list = makeQualifyingList_qtl7(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl7();
        $oldClub = makeClub_qtl7('Alter Verein');
        $newClub = makeClub_qtl7('Neuer Verein');
        $athlete = makeAthlete_qtl7($oldClub, 'Anna', 'Musterfrau');
        qualifyAthlete_qtl7($list, $stroke, $oldClub, $athlete, 5900);

        $this->actingAs(makeAdmin_qtl7())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list));

        // Vereinswechsel NACH der Berechnung
        $athlete->update(['club_id' => $newClub->id]);

        $qualification = Qualification::first();
        expect($qualification->club_id)->toBe($oldClub->id)
            ->and($qualification->club->name)->toBe('Alter Verein');

        $this->actingAs(makeClubUser_qtl7())
            ->get(route('qualifying-time-lists.qualifications', $list))
            ->assertSee('Alter Verein')
            ->assertDontSee('Neuer Verein');
    })->group('qualifying-time-lists-p5-p6');

    it('filtert nach Sportklasse', function () {
        $list = makeQualifyingList_qtl7(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl7();
        $club = makeClub_qtl7('Verein A');
        makeQualifyingTime_qtl7($list, $stroke, 100, 'M', 'S9', 6000);
        makeQualifyingTime_qtl7($list, $stroke, 200, 'M', 'S2', 6000);

        $meet = makeMeet_qtl7('2026-08-01');
        $athleteS9 = makeAthlete_qtl7($club, 'Anna', 'NeunKlasse');
        makeResult_qtl7($meet, makeSwimEvent_qtl7($meet, $stroke), $athleteS9, $club, 5900);
        $athleteS2 = makeAthlete_qtl7($club, 'Bea', 'ZweiKlasse');
        makeResult_qtl7($meet, makeSwimEvent_qtl7($meet, $stroke, 200), $athleteS2, $club, 5900, 'S2');

        $this->actingAs(makeAdmin_qtl7())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list));

        expect(Qualification::count())->toBe(2);

        $this->actingAs(makeClubUser_qtl7())
            ->get(route('qualifying-time-lists.qualifications', $list).'?sport_class=S9')
            ->assertSee('NeunKlasse')
            ->assertDontSee('ZweiKlasse');
    })->group('qualifying-time-lists-p5-p6');

    it('filtert nach Verein', function () {
        $list = makeQualifyingList_qtl7(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl7();
        $clubA = makeClub_qtl7('Verein A');
        $clubB = makeClub_qtl7('Verein B');
        makeQualifyingTime_qtl7($list, $stroke, 100, 'M', 'S9', 6000);

        $meet = makeMeet_qtl7('2026-08-01');
        $athleteA = makeAthlete_qtl7($clubA, 'Carla', 'VereinA');
        makeResult_qtl7($meet, makeSwimEvent_qtl7($meet, $stroke), $athleteA, $clubA, 5900);
        $athleteB = makeAthlete_qtl7($clubB, 'Dora', 'VereinB');
        makeResult_qtl7($meet, makeSwimEvent_qtl7($meet, $stroke), $athleteB, $clubB, 5850);

        $this->actingAs(makeAdmin_qtl7())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list));

        $qualB = Qualification::whereHas('club', fn ($q) => $q->where('name', 'Verein B'))->first();

        $this->actingAs(makeClubUser_qtl7())
            ->get(route('qualifying-time-lists.qualifications', $list).'?club_id='.$qualB->club_id)
            ->assertSee('VereinB')
            ->assertDontSee('VereinA');
    })->group('qualifying-time-lists-p5-p6');

    it('filtert per Freitextsuche nach Name', function () {
        $list = makeQualifyingList_qtl7(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl7();
        $club = makeClub_qtl7('Verein A');
        makeQualifyingTime_qtl7($list, $stroke, 100, 'M', 'S9', 6000);

        $meet = makeMeet_qtl7('2026-08-01');
        $athlete1 = makeAthlete_qtl7($club, 'Findbar', 'Sucher');
        makeResult_qtl7($meet, makeSwimEvent_qtl7($meet, $stroke), $athlete1, $club, 5900);
        $athlete2 = makeAthlete_qtl7($club, 'Anders', 'Person');
        makeResult_qtl7($meet, makeSwimEvent_qtl7($meet, $stroke), $athlete2, $club, 5850);

        $this->actingAs(makeAdmin_qtl7())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list));

        $this->actingAs(makeClubUser_qtl7())
            ->get(route('qualifying-time-lists.qualifications', $list).'?search=Findbar')
            ->assertSee('Sucher')
            ->assertDontSee('Person');
    })->group('qualifying-time-lists-p5-p6');
});

// ── Phase 5: Historisierung/Isolation ────────────────────────────────────────────

describe('Historisierung der Qualifikationen (Phase 5)', function () {
    it('Neuberechnung einer Liste verändert die Qualifikationen einer anderen Liste nicht', function () {
        $list2026 = makeQualifyingList_qtl7(2026, '2025-05-12', '2026-05-29');
        $stroke = makeStrokeType_qtl7();
        $club = makeClub_qtl7('Verein A');

        $athlete2026 = makeAthlete_qtl7($club, 'Alt', 'Jahr');
        qualifyAthlete_qtl7($list2026, $stroke, $club, $athlete2026, 5900);
        $this->actingAs(makeAdmin_qtl7())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list2026));

        // list2027 erst NACH der 2026er-Berechnung anlegen, da nur die
        // jeweils aktuellste Liste berechnet werden darf (Phase 3).
        $list2027 = makeQualifyingList_qtl7(2027, '2026-05-12', '2027-05-29');
        $athlete2027 = makeAthlete_qtl7($club, 'Neu', 'Jahr');
        qualifyAthlete_qtl7($list2027, $stroke, $club, $athlete2027, 5900);
        $this->actingAs(makeAdmin_qtl7())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list2027));

        expect(Qualification::where('qualifying_time_list_id', $list2026->id)->count())->toBe(1)
            ->and(Qualification::where('qualifying_time_list_id', $list2027->id)->count())->toBe(1)
            ->and(Qualification::where('qualifying_time_list_id',
                $list2026->id)->first()->athlete_id)->toBe($athlete2026->id);
    })->group('qualifying-time-lists-p5-p6');

    it('Löschen einer Liste kaskadiert nur die eigenen Qualifikationen', function () {
        $list2026 = makeQualifyingList_qtl7(2026, '2025-05-12', '2026-05-29');
        $stroke = makeStrokeType_qtl7();
        $club = makeClub_qtl7('Verein A');

        $athlete2026 = makeAthlete_qtl7($club, 'Alt', 'Jahr');
        qualifyAthlete_qtl7($list2026, $stroke, $club, $athlete2026, 5900);
        $this->actingAs(makeAdmin_qtl7())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list2026));

        $list2027 = makeQualifyingList_qtl7(2027, '2026-05-12', '2027-05-29');
        $athlete2027 = makeAthlete_qtl7($club, 'Neu', 'Jahr');
        qualifyAthlete_qtl7($list2027, $stroke, $club, $athlete2027, 5900);
        $this->actingAs(makeAdmin_qtl7())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list2027));

        // 2027 ist aktuell -> löschbar
        $this->actingAs(makeAdmin_qtl7())->delete(route('qualifying-time-lists.destroy', $list2027));

        expect(Qualification::where('qualifying_time_list_id', $list2026->id)->count())->toBe(1)
            ->and(Qualification::where('qualifying_time_list_id', $list2027->id)->count())->toBe(0);
    })->group('qualifying-time-lists-p5-p6');
});

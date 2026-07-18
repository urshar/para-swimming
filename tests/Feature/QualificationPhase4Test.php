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

function makeAdmin_qtl6(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_qtl6(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_qtl6(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], [
        'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
    ]);
}

function makeClub_qtl6(): Club
{
    static $i = 0;
    $i++;

    return Club::create([
        'name' => 'Testverein '.$i, 'short_name' => 'TV'.$i, 'code' => 'TV'.$i,
        'nation_id' => makeNation_qtl6()->id,
    ]);
}

function makeAthlete_qtl6(Club $club, string $gender = 'M'): Athlete
{
    static $j = 0;
    $j++;

    return Athlete::create([
        'first_name' => 'Max', 'last_name' => 'Muster'.$j, 'gender' => $gender,
        'nation_id' => makeNation_qtl6()->id, 'club_id' => $club->id,
    ]);
}

function makeStrokeType_qtl6(string $lenexCode = 'FREE'): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $lenexCode], [
        'name_de' => $lenexCode, 'name_en' => $lenexCode, 'code' => strtolower($lenexCode),
    ]);
}

function makeQualifyingList_qtl6(
    int $year,
    ?string $periodStart = null,
    ?string $periodEnd = null,
): QualifyingTimeList {
    return QualifyingTimeList::create([
        'year' => $year, 'is_active' => true,
        'qualification_period_start' => $periodStart,
        'qualification_period_end' => $periodEnd,
    ]);
}

function makeMeet_qtl6(string $startDate): Meet
{
    static $k = 0;
    $k++;

    return Meet::create([
        'name' => 'Meet '.$k, 'nation_id' => makeNation_qtl6()->id, 'course' => 'LCM',
        'start_date' => $startDate,
    ]);
}

function makeSwimEvent_qtl6(Meet $meet, StrokeType $stroke, int $distance = 100, int $relayCount = 1): SwimEvent
{
    return SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => $distance,
        'relay_count' => $relayCount, 'gender' => 'M',
    ]);
}

function makeResult_qtl6(
    Meet $meet,
    SwimEvent $event,
    Athlete $athlete,
    Club $club,
    int $swimTime,
    string $sportClass = 'S9',
    ?string $status = null,
): Result {
    static $l = 0;
    $l++;

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'swim_time' => $swimTime, 'status' => $status,
        'sport_class' => $sportClass, 'points' => 500, 'lane' => $l,
    ]);
}

function makeQualifyingTime_qtl6(
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

// ── Berechtigungen ────────────────────────────────────────────────────────────

describe('QualifyingTimeListController::calculateQualifications — Berechtigungen', function () {
    it('Club-User bekommt 403', function () {
        $list = makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');

        $this->actingAs(makeClubUser_qtl6())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list))
            ->assertForbidden();
    })->group('qualifying-time-lists-p4');

    it('Admin bekommt 403 bei einer historisierten (nicht-aktuellsten) Liste', function () {
        $old = makeQualifyingList_qtl6(2026, '2025-05-12', '2026-05-29');
        makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');

        $this->actingAs(makeAdmin_qtl6())
            ->post(route('qualifying-time-lists.qualifications.calculate', $old))
            ->assertForbidden();
    })->group('qualifying-time-lists-p4');
});

// ── Fehlerfälle ────────────────────────────────────────────────────────────────

describe('QualificationDeterminationService — Fehlerfälle', function () {
    it('meldet Fehler, wenn Zeitraum-Beginn nicht gesetzt ist', function () {
        $list = makeQualifyingList_qtl6(2027, null, '2027-05-29');

        $this->actingAs(makeAdmin_qtl6())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list))
            ->assertSessionHas('error');
    })->group('qualifying-time-lists-p4');

    it('meldet KEINEN Fehler, wenn Zeitraum-Ende fehlt — rechnet stattdessen vorläufig bis heute', function () {
        $periodStart = now()->subYear()->toDateString();
        $resultDate = now()->subMonth()->toDateString();

        $list = makeQualifyingList_qtl6(2027, $periodStart);
        $stroke = makeStrokeType_qtl6();
        makeQualifyingTime_qtl6($list, $stroke, 100, 'M', 'S9', 6000);

        $qualifyingMeet = makeMeet_qtl6($resultDate);
        $club = makeClub_qtl6();
        $athlete = makeAthlete_qtl6($club);
        makeResult_qtl6($qualifyingMeet, makeSwimEvent_qtl6($qualifyingMeet, $stroke), $athlete, $club, 5900);

        $this->actingAs(makeAdmin_qtl6())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list))
            ->assertSessionHas('success', function ($message) {
                return str_contains($message, 'vorläufig bis heute');
            });

        expect(Qualification::where('qualifying_time_list_id', $list->id)->count())->toBe(1);
    })->group('qualifying-time-lists-p4');

    it('meldet Fehler, wenn keine berechneten Richtzeiten vorhanden sind', function () {
        $list = makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');
        // Bewusst keine QualifyingTime angelegt

        $this->actingAs(makeAdmin_qtl6())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list))
            ->assertSessionHas('error');
    })->group('qualifying-time-lists-p4');
});

// ── Qualifikationslogik ──────────────────────────────────────────────────────────

describe('QualificationDeterminationService — Ermittlung', function () {
    it('qualifiziert einen Athleten, der die Richtzeit unterboten hat, ohne dass ein Ziel-Meet existiert', function () {
        $list = makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl6();
        makeQualifyingTime_qtl6($list, $stroke, 100, 'M', 'S9', 6000); // 60.00s

        $qualifyingMeet = makeMeet_qtl6('2026-08-01');
        $event = makeSwimEvent_qtl6($qualifyingMeet, $stroke);
        $club = makeClub_qtl6();
        $athlete = makeAthlete_qtl6($club);
        makeResult_qtl6($qualifyingMeet, $event, $athlete, $club, 5900); // 59.00s -> schneller als Richtzeit

        $this->actingAs(makeAdmin_qtl6())
            ->post(route('qualifying-time-lists.qualifications.calculate', $list))
            ->assertRedirect();

        $qualification = Qualification::where('qualifying_time_list_id', $list->id)->first();
        expect($qualification)->not->toBeNull()
            ->and($qualification->meet_id)->toBeNull() // kein Ziel-Meet zugeordnet -> bleibt null
            ->and($qualification->athlete_id)->toBe($athlete->id)
            ->and($qualification->swim_time_centiseconds)->toBe(5900)
            ->and($qualification->sport_class)->toBe('S9')
            ->and($qualification->club_id)->toBe($club->id)
            ->and($qualification->qualified_at->toDateString())->toBe('2026-08-01');
    })->group('qualifying-time-lists-p4');

    it('qualifiziert einen Athleten NICHT, der die Richtzeit nicht erreicht hat', function () {
        $list = makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl6();
        makeQualifyingTime_qtl6($list, $stroke, 100, 'M', 'S9', 6000);

        $qualifyingMeet = makeMeet_qtl6('2026-08-01');
        $event = makeSwimEvent_qtl6($qualifyingMeet, $stroke);
        $club = makeClub_qtl6();
        $athlete = makeAthlete_qtl6($club);
        makeResult_qtl6($qualifyingMeet, $event, $athlete, $club, 6100); // 61.00s -> langsamer

        $this->actingAs(makeAdmin_qtl6())->post(route('qualifying-time-lists.qualifications.calculate', $list));

        expect(Qualification::count())->toBe(0);
    })->group('qualifying-time-lists-p4');

    it('speichert bei mehreren erreichten Ergebnissen nur die schnellste Zeit', function () {
        $list = makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl6();
        makeQualifyingTime_qtl6($list, $stroke, 100, 'M', 'S9', 6000);

        $club = makeClub_qtl6();
        $athlete = makeAthlete_qtl6($club);

        $meet1 = makeMeet_qtl6('2026-08-01');
        makeResult_qtl6($meet1, makeSwimEvent_qtl6($meet1, $stroke), $athlete, $club, 5900);
        $meet2 = makeMeet_qtl6('2026-09-01');
        makeResult_qtl6($meet2, makeSwimEvent_qtl6($meet2, $stroke), $athlete, $club, 5800); // schneller

        $this->actingAs(makeAdmin_qtl6())->post(route('qualifying-time-lists.qualifications.calculate', $list));

        expect(Qualification::where('qualifying_time_list_id', $list->id)->count())->toBe(1)
            ->and(Qualification::first()->swim_time_centiseconds)->toBe(5800);
    })->group('qualifying-time-lists-p4');

    it('ignoriert Staffel-Ergebnisse', function () {
        $list = makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl6();
        makeQualifyingTime_qtl6($list, $stroke, 100, 'M', 'S9', 6000);

        $qualifyingMeet = makeMeet_qtl6('2026-08-01');
        $relayEvent = makeSwimEvent_qtl6($qualifyingMeet, $stroke, relayCount: 4); // Staffel
        $club = makeClub_qtl6();
        $athlete = makeAthlete_qtl6($club);
        makeResult_qtl6($qualifyingMeet, $relayEvent, $athlete, $club, 5900);

        $this->actingAs(makeAdmin_qtl6())->post(route('qualifying-time-lists.qualifications.calculate', $list));

        expect(Qualification::count())->toBe(0);
    })->group('qualifying-time-lists-p4');

    it('ignoriert Ergebnisse mit Status (z.B. DSQ)', function () {
        $list = makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl6();
        makeQualifyingTime_qtl6($list, $stroke, 100, 'M', 'S9', 6000);

        $qualifyingMeet = makeMeet_qtl6('2026-08-01');
        $event = makeSwimEvent_qtl6($qualifyingMeet, $stroke);
        $club = makeClub_qtl6();
        $athlete = makeAthlete_qtl6($club);
        makeResult_qtl6($qualifyingMeet, $event, $athlete, $club, 5900, status: 'DSQ');

        $this->actingAs(makeAdmin_qtl6())->post(route('qualifying-time-lists.qualifications.calculate', $list));

        expect(Qualification::count())->toBe(0);
    })->group('qualifying-time-lists-p4');

    it('ignoriert Ergebnisse außerhalb des Qualifikationszeitraums', function () {
        $list = makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl6();
        makeQualifyingTime_qtl6($list, $stroke, 100, 'M', 'S9', 6000);

        // Vor Zeitraumbeginn
        $tooEarly = makeMeet_qtl6('2026-01-01');
        $club = makeClub_qtl6();
        $athlete = makeAthlete_qtl6($club);
        makeResult_qtl6($tooEarly, makeSwimEvent_qtl6($tooEarly, $stroke), $athlete, $club, 5900);

        // Nach Zeitraumende
        $tooLate = makeMeet_qtl6('2027-06-01');
        makeResult_qtl6($tooLate, makeSwimEvent_qtl6($tooLate, $stroke), $athlete, $club, 5900);

        $this->actingAs(makeAdmin_qtl6())->post(route('qualifying-time-lists.qualifications.calculate', $list));

        expect(Qualification::count())->toBe(0);
    })->group('qualifying-time-lists-p4');

    it('ersetzt bei erneuter Berechnung bestehende Einträge vollständig', function () {
        $list = makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl6();
        makeQualifyingTime_qtl6($list, $stroke, 100, 'M', 'S9', 6000);

        $qualifyingMeet = makeMeet_qtl6('2026-08-01');
        $club = makeClub_qtl6();
        $athlete1 = makeAthlete_qtl6($club);
        makeResult_qtl6($qualifyingMeet, makeSwimEvent_qtl6($qualifyingMeet, $stroke), $athlete1, $club, 5900);

        $this->actingAs(makeAdmin_qtl6())->post(route('qualifying-time-lists.qualifications.calculate', $list));
        expect(Qualification::where('qualifying_time_list_id', $list->id)->count())->toBe(1);

        // Athlet 1 disqualifiziert nachträglich, neuer Athlet 2 qualifiziert
        Result::where('athlete_id', $athlete1->id)->update(['status' => 'DSQ']);
        $athlete2 = makeAthlete_qtl6($club);
        makeResult_qtl6($qualifyingMeet, makeSwimEvent_qtl6($qualifyingMeet, $stroke, 200), $athlete2, $club, 5900);
        makeQualifyingTime_qtl6($list, $stroke, 200, 'M', 'S9', 6000);

        $this->actingAs(makeAdmin_qtl6())->post(route('qualifying-time-lists.qualifications.calculate', $list));

        $remaining = Qualification::where('qualifying_time_list_id', $list->id)->get();
        expect($remaining)->toHaveCount(1)
            ->and($remaining->first()->athlete_id)->toBe($athlete2->id);
    })->group('qualifying-time-lists-p4');

    it('übernimmt eine bereits zugeordnete Ziel-Meet-Referenz rein informativ', function () {
        $list = makeQualifyingList_qtl6(2027, '2026-05-12', '2027-05-29');
        $stroke = makeStrokeType_qtl6();
        makeQualifyingTime_qtl6($list, $stroke, 100, 'M', 'S9', 6000);

        $targetMeet = makeMeet_qtl6('2027-06-12');
        $targetMeet->update(['qualifying_time_list_id' => $list->id]);

        $qualifyingMeet = makeMeet_qtl6('2026-08-01');
        $club = makeClub_qtl6();
        $athlete = makeAthlete_qtl6($club);
        makeResult_qtl6($qualifyingMeet, makeSwimEvent_qtl6($qualifyingMeet, $stroke), $athlete, $club, 5900);

        $this->actingAs(makeAdmin_qtl6())->post(route('qualifying-time-lists.qualifications.calculate', $list));

        expect(Qualification::first()->meet_id)->toBe($targetMeet->id);
    })->group('qualifying-time-lists-p4');
});

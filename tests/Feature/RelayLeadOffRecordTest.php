<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\RelayResultSplit;
use App\Models\Result;
use App\Models\ResultSplit;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('split-records-p4');

// Staffel-Startschwimmer (docs/specs/split-records.md, Phase 4): Die volle erste Teilstrecke einer Freistilstaffel
// kann einen Einzelrekord des Startschwimmers aufstellen — immer ausstehend, je Kategorie und Wettkampf nur die
// schnellste Zwischenzeit.

function base_rlo(): array
{
    $austria = Nation::forceCreate(['code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    $club = Club::query()->create(['name' => 'Verein Wien', 'nation_id' => $austria->id, 'type' => 'CLUB',
        'regional_association' => 'WBSV']);
    $meet = Meet::query()->create(['name' => 'ÖSTM 2018', 'start_date' => '2018-05-05', 'end_date' => '2018-05-05',
        'course' => 'SCM', 'city' => 'Graz', 'nation_id' => $austria->id, 'timing' => 'AUTOMATIC']);

    return compact('austria', 'club', 'meet');
}

function stroke_rlo(string $code): StrokeType
{
    return StrokeType::query()->firstOrCreate(['lenex_code' => $code],
        ['name_de' => $code === 'FREE' ? 'Freistil' : 'Lagen', 'name_en' => $code, 'code' => $code]);
}

function athlete_rlo(array $base, string $lastName, string $gender = 'M', ?Nation $nation = null): Athlete
{
    return Athlete::query()->create(['first_name' => 'Max', 'last_name' => $lastName, 'gender' => $gender,
        'birth_date' => '1980-01-01', 'nation_id' => ($nation ?? $base['austria'])->id, 'club_id' => $base['club']->id]);
}

/**
 * Staffel (Klasse S9 je Mitglied) mit Zwischenzeiten [Distanz => Zeit]; der erste Athlet ist der Startschwimmer.
 *
 * @param  list<Athlete>  $athletes
 * @param  array<int, int>  $splits
 */
function relay_rlo(array $base, string $stroke, int $legDistance, array $athletes, array $splits, string $gender = 'M'): RelayResult
{
    static $number = 0;
    $number++;

    $event = SwimEvent::query()->create(['meet_id' => $base['meet']->id, 'stroke_type_id' => stroke_rlo($stroke)->id,
        'distance' => $legDistance, 'relay_count' => 4, 'gender' => $gender, 'session_number' => 1,
        'event_number' => $number, 'round' => 'TIM']);
    $relay = RelayResult::query()->create(['meet_id' => $base['meet']->id, 'swim_event_id' => $event->id,
        'club_id' => $base['club']->id, 'gender' => $gender, 'swim_time' => max($splits)]);

    foreach ($athletes as $i => $athlete) {
        RelayResultMember::query()->create(['relay_result_id' => $relay->id, 'position' => $i + 1,
            'athlete_id' => $athlete->id, 'gender' => $athlete->gender, 'sport_class' => 'S9']);
    }
    foreach ($splits as $distance => $time) {
        RelayResultSplit::query()->create(['relay_result_id' => $relay->id, 'distance' => $distance, 'split_time' => $time]);
    }

    return $relay;
}

/** @return list<Athlete> vier österreichische Herren */
function team_rlo(array $base): array
{
    return array_map(fn (int $i): Athlete => athlete_rlo($base, 'Staffel'.$i), range(1, 4));
}

/** @throws Throwable */
function check_rlo(array $base): array
{
    return app(RecordCheckerService::class)->checkMeet($base['meet']);
}

/** @return list<SwimRecord> Einzelrekorde aus Staffel-Startschwimmern */
function leadOffRecords_rlo(): array
{
    return SwimRecord::query()->where('from_split', true)->whereNotNull('relay_result_id')
        ->orderBy('record_type')->get()->all();
}

it('legt aus der ersten Teilstrecke einer Freistilstaffel einen ausstehenden Einzelrekord des Startschwimmers an', /** @throws Throwable */ function () {
    $base = base_rlo();
    $team = team_rlo($base);
    $relay = relay_rlo($base, 'FREE', 50, $team, [50 => 2900, 100 => 6000, 150 => 9100, 200 => 12000]);

    $outcome = check_rlo($base);
    $records = leadOffRecords_rlo();

    expect(collect($records)->pluck('record_type')->all())->toBe(['AUT', 'AUT.WBSV'])
        ->and($records[0]->relay_count)->toBe(1)
        ->and($records[0]->distance)->toBe(50)
        ->and($records[0]->swim_time)->toBe(2900)
        ->and($records[0]->sport_class)->toBe('S9')
        ->and($records[0]->gender)->toBe('M')
        ->and($records[0]->athlete_id)->toBe($team[0]->id)
        ->and($records[0]->relay_result_id)->toBe($relay->id)
        ->and($records[0]->club_id)->toBe($base['club']->id)
        ->and($records[0]->record_status)->toBe('PENDING')
        ->and($records[0]->comment)->toBe('Startschwimmer 4×50 m Freistil')
        ->and($records[0]->relayTeam()->count())->toBe(0)
        ->and(collect($outcome['pending_records'])->where('record.relay_count', 1)->pluck('reason')->unique()->all())
        ->toBe([RecordCheckerService::PENDING_SPLIT]);
});

it('wertet nur die volle erste Teilstrecke, keine Zwischenzeit darin', /** @throws Throwable */ function () {
    $base = base_rlo();
    relay_rlo($base, 'FREE', 100, team_rlo($base), [50 => 2900, 100 => 6000, 200 => 12500, 400 => 25000]);

    check_rlo($base);
    $record = SwimRecord::query()->where('from_split', true)->where('record_type', 'AUT')->sole();

    expect($record->distance)->toBe(100)
        ->and($record->swim_time)->toBe(6000)
        ->and($record->splits()->pluck('distance')->all())->toBe([50]);
});

it('wertet die erste Teilstrecke einer Lagenstaffel nie', /** @throws Throwable */ function () {
    $base = base_rlo();
    relay_rlo($base, 'MEDLEY', 50, team_rlo($base), [50 => 3300, 100 => 7000, 150 => 10000, 200 => 13000]);

    check_rlo($base);

    expect(leadOffRecords_rlo())->toBe([]);
});

it('nimmt Geschlecht des Startschwimmers, nicht die Wertung der Staffel', /** @throws Throwable */ function () {
    $base = base_rlo();
    $team = [athlete_rlo($base, 'Startschwimmerin', 'F'), athlete_rlo($base, 'B', 'F'),
        athlete_rlo($base, 'C'), athlete_rlo($base, 'D')];
    relay_rlo($base, 'FREE', 50, $team, [50 => 3200, 200 => 12500], 'X');

    check_rlo($base);

    expect(collect(leadOffRecords_rlo())->pluck('gender')->unique()->all())->toBe(['F']);
});

it('legt keinen Rekord an, wenn der Startschwimmer keine österreichische Nationalität hat', /** @throws Throwable */ function () {
    $base = base_rlo();
    $germany = Nation::forceCreate(['code' => 'GER', 'name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);
    $team = team_rlo($base);
    $team[0] = athlete_rlo($base, 'Gast', 'M', $germany);
    relay_rlo($base, 'FREE', 50, $team, [50 => 2900, 200 => 12000]);

    check_rlo($base);

    expect(leadOffRecords_rlo())->toBe([]);
});

it('legt keinen Rekord an, wenn der Startschwimmer am Starttag für einen anderen Verein startete', /** @throws Throwable */ function () {
    $base = base_rlo();
    $team = team_rlo($base);
    $other = Club::query()->create(['name' => 'Anderer Verein', 'nation_id' => $base['austria']->id, 'type' => 'CLUB']);
    // Einzelergebnis im selben Wettkampf für den anderen Verein — der stärkste Beleg (clubEvidence()).
    $event = SwimEvent::query()->create(['meet_id' => $base['meet']->id, 'stroke_type_id' => stroke_rlo('FREE')->id,
        'distance' => 400, 'relay_count' => 1, 'gender' => 'M', 'session_number' => 1, 'event_number' => 99,
        'round' => 'TIM']);
    Result::query()->create(['meet_id' => $base['meet']->id, 'swim_event_id' => $event->id,
        'athlete_id' => $team[0]->id, 'club_id' => $other->id, 'sport_class' => 'S9', 'swim_time' => 40000]);
    relay_rlo($base, 'FREE', 50, $team, [50 => 2900, 200 => 12000]);

    check_rlo($base);

    expect(leadOffRecords_rlo())->toBe([]);
});

it('legt je Kategorie nur aus der schnellsten Zwischenzeit von Einzelrennen und Staffeln einen Rekord an', /** @throws Throwable */ function () {
    $base = base_rlo();
    $team = team_rlo($base);
    // 50-m-Zwischenzeit 29,50 im 100-m-Einzelrennen eines anderen Athleten, Startschwimmer der Staffel 29,00.
    $single = athlete_rlo($base, 'Einzel');
    $event = SwimEvent::query()->create(['meet_id' => $base['meet']->id, 'stroke_type_id' => stroke_rlo('FREE')->id,
        'distance' => 100, 'relay_count' => 1, 'gender' => 'M', 'session_number' => 1, 'event_number' => 98,
        'round' => 'TIM']);
    $result = Result::query()->create(['meet_id' => $base['meet']->id, 'swim_event_id' => $event->id,
        'athlete_id' => $single->id, 'club_id' => $base['club']->id, 'sport_class' => 'S9', 'swim_time' => 6200]);
    ResultSplit::query()->create(['result_id' => $result->id, 'distance' => 50, 'split_time' => 2950]);
    $relay = relay_rlo($base, 'FREE', 50, $team, [50 => 2900, 200 => 12000]);

    check_rlo($base);
    $aut50 = SwimRecord::query()->where('from_split', true)->where('record_type', 'AUT')->where('distance', 50)->get();

    expect($aut50)->toHaveCount(1)
        ->and($aut50->first()->relay_result_id)->toBe($relay->id);
});

it('legt beim erneuten Rekord-Check keinen Startschwimmer-Rekord doppelt an', /** @throws Throwable */ function () {
    $base = base_rlo();
    relay_rlo($base, 'FREE', 50, team_rlo($base), [50 => 2900, 200 => 12000]);

    check_rlo($base);
    check_rlo($base);

    expect(leadOffRecords_rlo())->toHaveCount(2);
});

<?php

use App\Models\Athlete;
use App\Models\AthleteClubHistory;
use App\Models\AthleteSportClass;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relay-record-club');

// Staffelrekord nur, wenn alle Mitglieder am Starttag beim Staffelverein waren (docs/specs/records.md).

function club_rlc(Nation $nation, string $name): Club
{
    return Club::create(['name' => $name, 'nation_id' => $nation->id, 'type' => 'CLUB']);
}

function event_rlc(Meet $meet, StrokeType $stroke, int $relayCount, int $number): SwimEvent
{
    return SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 50, 'relay_count' => $relayCount,
        'gender' => 'M', 'session_number' => 1, 'event_number' => $number, 'round' => 'TIM',
    ]);
}

/** Vier S5-Athleten, heute alle beim angegebenen Verein; liefert sie als Liste. */
function athletes_rlc(Nation $nation, Club $club): array
{
    $athletes = [];
    for ($i = 1; $i <= 4; $i++) {
        $athlete = Athlete::create([
            'first_name' => 'Test'.$i, 'last_name' => 'Staffel', 'gender' => 'M', 'birth_date' => '1990-06-01',
            'nation_id' => $nation->id, 'club_id' => $club->id,
        ]);
        AthleteSportClass::create([
            'athlete_id' => $athlete->id, 'category' => 'S', 'class_number' => '5', 'sport_class' => 'S5',
        ]);
        $athletes[] = $athlete;
    }

    return $athletes;
}

/** @param list<Athlete> $athletes */
function relay_rlc(Meet $meet, SwimEvent $event, Club $club, array $athletes): RelayResult
{
    $relay = RelayResult::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'gender' => 'M',
        'swim_time' => 5000,
    ]);
    foreach ($athletes as $i => $athlete) {
        RelayResultMember::create([
            'relay_result_id' => $relay->id, 'position' => $i + 1, 'athlete_id' => $athlete->id, 'gender' => 'M',
            'sport_class' => 'S5',
        ]);
    }

    return $relay;
}

function individual_rlc(Meet $meet, SwimEvent $event, Athlete $athlete, Club $club): void
{
    Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S5', 'swim_time' => 9000,
    ]);
}

/**
 * Staffelrekorde aus checkMeet(), getrennt in neue und ausstehende (Einzelergebnisse der Testdaten erzeugen selbst
 * Einzelrekorde). checkMeet() deklariert Throwable; im Test führt jede Exception ohnehin zum Fehlschlag.
 *
 * @return array{new: list<array>, pending: list<array>}
 */
function check_rlc(Meet $meet): array
{
    try {
        $result = app(RecordCheckerService::class)->checkMeet($meet);
    } catch (Throwable $e) {
        throw new LogicException($e->getMessage(), 0, $e);
    }
    $relay = fn (array $items) => array_values(array_filter($items, fn (array $i) => $i['record']->relay_count > 1));

    return ['new' => $relay($result['new_records']), 'pending' => $relay($result['pending_records'])];
}

function relayRecord_rlc(): SwimRecord
{
    return SwimRecord::where('relay_count', 4)->where('record_type', 'AUT')->sole();
}

beforeEach(function () {
    $this->aut = Nation::forceCreate(['code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria',
        'is_active' => true]);
    $this->relayClub = club_rlc($this->aut, 'Staffelverein');
    $this->newClub = club_rlc($this->aut, 'Neuer Verein');
    $this->meet = Meet::create(['name' => 'LM 2025', 'start_date' => '2025-03-15', 'end_date' => '2025-03-15',
        'course' => 'SCM', 'city' => 'Wien', 'nation_id' => $this->aut->id]);
    $stroke = StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE',
        'code' => 'FREE']);
    $this->relayEvent = event_rlc($this->meet, $stroke, 4, 1);
    $this->singleEvent = event_rlc($this->meet, $stroke, 1, 2);
    $this->athletes = athletes_rlc($this->aut, $this->relayClub);
});

it('zählt ein Mitglied, das nach dem Start den Verein gewechselt hat, über sein Einzelergebnis', function () {
    $moved = $this->athletes[0];
    $moved->update(['club_id' => $this->newClub->id]);
    individual_rlc($this->meet, $this->singleEvent, $moved, $this->relayClub);
    relay_rlc($this->meet, $this->relayEvent, $this->relayClub, $this->athletes);

    $result = check_rlc($this->meet);

    expect($result['new'])->toHaveCount(1)
        ->and($result['pending'])->toBeEmpty()
        ->and(relayRecord_rlc()->record_status)->toBe('APPROVED');
});

it('legt keinen Rekord an, wenn ein Mitglied im selben Wettkampf für einen anderen Verein startete', function () {
    // Heute beim Staffelverein, am Starttag laut Einzelergebnis aber beim anderen Verein (gemischte Staffel).
    individual_rlc($this->meet, $this->singleEvent, $this->athletes[1], $this->newClub);
    relay_rlc($this->meet, $this->relayEvent, $this->relayClub, $this->athletes);

    $result = check_rlc($this->meet);

    expect($result['new'])->toBeEmpty()
        ->and($result['pending'])->toBeEmpty()
        ->and(SwimRecord::where('relay_count', 4)->count())->toBe(0);
});

it('nimmt ohne Einzelergebnis die Vereins-History zum Wettkampfdatum', function () {
    $moved = $this->athletes[2];
    $moved->update(['club_id' => $this->newClub->id]);
    AthleteClubHistory::create(['athlete_id' => $moved->id, 'club_id' => $this->relayClub->id,
        'joined_at' => '2020-01-01', 'left_at' => '2025-06-30', 'is_active' => false]);
    AthleteClubHistory::create(['athlete_id' => $moved->id, 'club_id' => $this->newClub->id,
        'joined_at' => '2025-07-01', 'is_active' => true]);
    relay_rlc($this->meet, $this->relayEvent, $this->relayClub, $this->athletes);

    $result = check_rlc($this->meet);

    expect($result['new'])->toHaveCount(1)
        ->and($result['pending'])->toBeEmpty();
});

it('legt den Rekord ausstehend an, wenn die Zugehörigkeit nicht belegt ist', function () {
    $this->athletes[3]->update(['club_id' => $this->newClub->id]);
    $relay = relay_rlc($this->meet, $this->relayEvent, $this->relayClub, $this->athletes);

    $result = check_rlc($this->meet);

    expect($result['new'])->toBeEmpty()
        ->and($result['pending'])->toHaveCount(1)
        ->and($result['pending'][0]['reason'])->toBe(RecordCheckerService::PENDING_CLUB)
        ->and(relayRecord_rlc()->record_status)->toBe('PENDING')
        ->and($relay->fresh()->is_national_record)->toBeFalse();
});

it('zählt ohne Beleg den heutigen Verein, wenn er der Staffelverein ist', function () {
    relay_rlc($this->meet, $this->relayEvent, $this->relayClub, $this->athletes);

    $result = check_rlc($this->meet);

    expect($result['new'])->toHaveCount(1)
        ->and($result['pending'])->toBeEmpty()
        ->and(relayRecord_rlc()->record_status)->toBe('APPROVED');
});

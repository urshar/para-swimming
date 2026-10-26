<?php

use App\Models\Athlete;
use App\Models\AthleteLevelHistory;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Services\ScoringGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scoring-group-restrictions-p1');

// Wertungsgruppen nach Landesverband und Level (docs/specs/scoring-group-restrictions.md).
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php). makeMeet_p5(): LCM, 15.06.2025.

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function club_sgr(?string $association): Club
{
    $club = makeClub_p5();
    $club->update(['regional_association' => $association]);

    return $club;
}

function group_sgr(SwimEvent $event, string $name, int $order, ?string $associations, ?string $mode, ?string $levels): ScoringGroup
{
    return ScoringGroup::create([
        'swim_event_id' => $event->id,
        'name' => $name,
        'gender' => 'A',
        'sport_classes' => null,
        'regional_associations' => $associations,
        'regional_mode' => $mode,
        'levels' => $levels,
        'sort_order' => $order,
    ]);
}

function athlete_sgr(Club $club, ?string $level): Athlete
{
    $athlete = makeAthlete_p5($club);
    $athlete->update(['birth_date' => '1990-01-01', 'level' => $level]);

    return $athlete;
}

function result_sgr(Meet $meet, SwimEvent $event, Athlete $athlete, Club $club, int $points): Result
{
    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => 10000 - $points,
        'points' => $points,
        'sport_class' => 'S9',
    ]);
}

/**
 * Gruppen als [Name => [[Ergebnis-ID, Platz], ...]].
 *
 * @return array<string, list<array{0: int, 1: ?int}>>
 */
function ranked_sgr(SwimEvent $event): array
{
    $results = $event->relay_count > 1
        ? RelayResult::where('swim_event_id', $event->id)->with('members')->get()
        : Result::where('swim_event_id', $event->id)->with('athlete')->get();

    return collect(app(ScoringGroupService::class)->rankedGroups($event->fresh(), $results, 2025))
        ->mapWithKeys(fn (array $g): array => [$g['name'] => array_map(fn (array $r): array => [$r['result']->id, $r['place']], $g['rows'])])
        ->all();
}

// ── Modell ────────────────────────────────────────────────────────────────────

it('prüft Landesverband und Level einer Wertungsgruppe', function () {
    $lm = new ScoringGroup(['gender' => 'A', 'regional_associations' => 'WBSV', 'regional_mode' => ScoringGroup::REGION_ONLY]);
    $guests = new ScoringGroup(['gender' => 'A', 'regional_associations' => 'wbsv', 'regional_mode' => ScoringGroup::REGION_EXCEPT]);
    $levelB = new ScoringGroup(['gender' => 'A', 'levels' => 'B, T']);

    expect($lm->matches('M', 9, 30, 'WBSV', null))->toBeTrue()
        ->and($lm->matches('M', 9, 30, 'SBSV', null))->toBeFalse()
        ->and($lm->matches('M', 9, 30, null, null))->toBeFalse()
        ->and($guests->matches('M', 9, 30, 'WBSV', null))->toBeFalse()
        ->and($guests->matches('M', 9, 30, 'SBSV', null))->toBeTrue()
        ->and($guests->matches('M', 9, 30, null, null))->toBeTrue()
        ->and($levelB->matches('M', 9, 30, null, 'B'))->toBeTrue()
        ->and($levelB->matches('M', 9, 30, null, 'A'))->toBeFalse()
        ->and($levelB->matches('M', 9, 30, null, null))->toBeFalse()
        ->and($lm->restrictionLabel())->toBe('nur WBSV')
        ->and($guests->restrictionLabel())->toBe('ohne WBSV')
        ->and($levelB->restrictionLabel())->toBe('Level B, T')
        ->and((new ScoringGroup(['gender' => 'A']))->restrictionLabel())->toBeNull();
});

it('vergleicht beliebige Level ohne Rücksicht auf Groß-/Kleinschreibung', function () {
    $group = new ScoringGroup(['gender' => 'A', 'levels' => 'Elite Kader; b']);

    expect($group->matches('M', 9, 30, null, 'elite kader'))->toBeTrue()
        ->and($group->matches('M', 9, 30, null, 'B'))->toBeTrue()
        ->and($group->matches('M', 9, 30, null, 'Elite'))->toBeFalse()
        ->and($group->levelCodes())->toBe(['b', 'Elite Kader'])
        ->and(ScoringGroup::parseLevels(' T, a;A, Talent '))->toBe(['a', 'T', 'Talent']);
});

// ── Wertung ───────────────────────────────────────────────────────────────────

it('wertet bei einer LM nur Vereine des Landesverbands, die anderen als Gäste', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    group_sgr($event, 'LM Wien', 1, 'WBSV', ScoringGroup::REGION_ONLY, null);
    group_sgr($event, 'Gäste', 2, 'WBSV', ScoringGroup::REGION_EXCEPT, null);

    $wien = club_sgr('WBSV');
    $salzburg = club_sgr('SBSV');
    $foreign = club_sgr(null);
    $guestBest = result_sgr($meet, $event, athlete_sgr($salzburg, null), $salzburg, 700);
    $wien1 = result_sgr($meet, $event, athlete_sgr($wien, null), $wien, 600);
    $foreignResult = result_sgr($meet, $event, athlete_sgr($foreign, null), $foreign, 500);
    $wien2 = result_sgr($meet, $event, athlete_sgr($wien, null), $wien, 400);

    $groups = ranked_sgr($event);

    // Plätze nur unter den Zugehörigen, ohne Lücken; kein Ergebnis bleibt ohne Gruppe.
    expect(array_keys($groups))->toBe(['LM Wien', 'Gäste'])
        ->and($groups['LM Wien'])->toBe([[$wien1->id, 1], [$wien2->id, 2]])
        ->and($groups['Gäste'])->toBe([[$guestBest->id, 1], [$foreignResult->id, 2]]);
});

it('nimmt den Verein im Ergebnis, nicht den aktuellen Verein des Athleten', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    group_sgr($event, 'LM Wien', 1, 'WBSV', ScoringGroup::REGION_ONLY, null);

    $wien = club_sgr('WBSV');
    $salzburg = club_sgr('SBSV');
    // Zum Start bei einem Salzburger Verein, inzwischen nach Wien gewechselt.
    $result = result_sgr($meet, $event, athlete_sgr($wien, null), $salzburg, 600);

    expect(ranked_sgr($event))->toBe([ScoringGroupService::UNASSIGNED_LABEL => [[$result->id, 1]]]);
});

it('wertet nach dem Level zum Wettkampfdatum, sonst nach dem aktuellen Level', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    group_sgr($event, 'B-Wertung', 1, null, null, 'B');
    $club = club_sgr('WBSV');

    $currentB = result_sgr($meet, $event, athlete_sgr($club, 'B'), $club, 500);
    $currentA = result_sgr($meet, $event, athlete_sgr($club, 'A'), $club, 400);
    $withoutLevel = result_sgr($meet, $event, athlete_sgr($club, null), $club, 300);

    // Heute A, zum Wettkampf (15.06.2025) B: zählt als B.
    $wasB = athlete_sgr($club, 'A');
    AthleteLevelHistory::create(['athlete_id' => $wasB->id, 'level' => 'B', 'changed_at' => '2024-01-01']);
    AthleteLevelHistory::create(['athlete_id' => $wasB->id, 'level' => 'A', 'changed_at' => '2026-01-01']);
    $wasBResult = result_sgr($meet, $event, $wasB, $club, 600);

    // Heute B, zum Wettkampf A: zählt nicht.
    $wasA = athlete_sgr($club, 'B');
    AthleteLevelHistory::create(['athlete_id' => $wasA->id, 'level' => 'A', 'changed_at' => '2025-06-15']);
    AthleteLevelHistory::create(['athlete_id' => $wasA->id, 'level' => 'B', 'changed_at' => '2025-06-16']);
    $wasAResult = result_sgr($meet, $event, $wasA, $club, 700);

    $groups = ranked_sgr($event);

    expect($groups['B-Wertung'])->toBe([[$wasBResult->id, 1], [$currentB->id, 2]])
        ->and(array_column($groups[ScoringGroupService::UNASSIGNED_LABEL], 0))
        ->toBe([$wasAResult->id, $currentA->id, $withoutLevel->id]);
});

it('wertet Staffeln nach dem Landesverband des Staffelvereins', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['distance' => 50, 'relay_count' => 4, 'gender' => 'A']);
    group_sgr($event, 'LM Wien', 1, 'WBSV', ScoringGroup::REGION_ONLY, null);
    group_sgr($event, 'Gäste', 2, 'WBSV', ScoringGroup::REGION_EXCEPT, null);

    $relay = fn (Club $club, int $points): RelayResult => RelayResult::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'gender' => 'M',
        'relay_class' => 'S14', 'swim_time' => 20000 - $points, 'points' => $points,
    ]);
    $guest = $relay(club_sgr('TBSV'), 500);
    $wien = $relay(club_sgr('WBSV'), 400);

    expect(ranked_sgr($event))->toBe(['LM Wien' => [[$wien->id, 1]], 'Gäste' => [[$guest->id, 1]]]);
});

it('speichert die Plätze nach dem Herausfiltern', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    group_sgr($event, 'LM Wien', 1, 'WBSV', ScoringGroup::REGION_ONLY, null);
    group_sgr($event, 'Gäste', 2, 'WBSV', ScoringGroup::REGION_EXCEPT, null);

    $wien = club_sgr('WBSV');
    $salzburg = club_sgr('SBSV');
    $guest = result_sgr($meet, $event, athlete_sgr($salzburg, null), $salzburg, 700);
    $wien1 = result_sgr($meet, $event, athlete_sgr($wien, null), $wien, 600);
    $wien2 = result_sgr($meet, $event, athlete_sgr($wien, null), $wien, 500);

    app(ScoringGroupService::class)->syncPlaces($event->fresh());

    expect($wien1->fresh()->place)->toBe(1)
        ->and($wien2->fresh()->place)->toBe(2)
        ->and($guest->fresh()->place)->toBe(1);
});

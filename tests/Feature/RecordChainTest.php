<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\RecordCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('split-records-p1');

// Chronologische Rekordkette (docs/specs/split-records.md): Maßstab ist der Rekord, der am Wettkampftag galt. Ein
// älterer Rekord kommt an seinem Datum in die Kette, spätere gleich schnelle oder langsamere werden INVALID.

function base_rch(): array
{
    $austria = Nation::forceCreate(['code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    $stroke = StrokeType::query()->create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);
    $club = Club::query()->create(['name' => 'Verein ÖBSV', 'nation_id' => $austria->id, 'type' => 'CLUB']);

    return compact('austria', 'stroke', 'club');
}

/** Wettkampf mit einem 100-m-Freistil-Ergebnis eines österreichischen Herren der Klasse S14. */
function result_rch(array $base, string $date, int $time, string $lastName = 'Huber'): Result
{
    $meet = Meet::query()->create(['name' => 'Meet '.$date, 'start_date' => $date, 'end_date' => $date,
        'course' => 'SCM', 'city' => 'Wien', 'nation_id' => $base['austria']->id]);
    $event = SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => $base['stroke']->id, 'distance' => 100,
        'relay_count' => 1, 'gender' => 'M', 'session_number' => 1, 'event_number' => 1, 'round' => 'TIM']);
    $athlete = Athlete::query()->create(['first_name' => 'Max', 'last_name' => $lastName, 'gender' => 'M',
        'birth_date' => '1980-01-01', 'nation_id' => $base['austria']->id, 'club_id' => $base['club']->id]);

    return Result::query()->create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $base['club']->id, 'sport_class' => 'S14', 'swim_time' => $time, 'heat' => 1]);
}

/** Anerkannter ÖR (100 m Freistil S14 SCM), wahlweise mit Ergebnis (für das Rekord-Flag). */
function record_rch(array $base, string $date, int $time, string $status = 'APPROVED', ?Result $result = null): SwimRecord
{
    return SwimRecord::query()->create(['stroke_type_id' => $base['stroke']->id, 'nation_id' => $base['austria']->id,
        'record_type' => 'AUT', 'sport_class' => 'S14', 'gender' => 'M', 'course' => 'SCM', 'distance' => 100,
        'relay_count' => 1, 'swim_time' => $time, 'record_status' => $status,
        'is_current' => $status === 'APPROVED', 'set_date' => $date, 'result_id' => $result?->id]);
}

/** Zwei anerkannte ÖR: 2010 (1:40,00) und 2015 (1:35,00), der jüngere aktuell. */
function chain_rch(array $base, ?Result $laterResult = null): array
{
    $first = record_rch($base, '2010-05-01', 10000, 'APPROVED.HISTORY');
    $second = record_rch($base, '2015-05-01', 9500, 'APPROVED', $laterResult);
    $second->update(['supersedes_id' => $first->id]);
    $first->update(['superseded_by_id' => $second->id, 'is_current' => false]);

    return [$first, $second];
}

/** @throws Throwable */
function checkMeet_rch(Result $result): array
{
    return app(RecordCheckerService::class)->checkMeet($result->meet);
}

it('reiht einen nachträglich geprüften älteren Rekord zwischen zwei bestehende ein', /** @throws Throwable */ function () {
    $base = base_rch();
    [$first, $second] = chain_rch($base);
    $result = result_rch($base, '2012-05-01', 9700);

    $outcome = checkMeet_rch($result);
    $new = SwimRecord::query()->where('result_id', $result->id)->sole();

    expect($outcome['new_records'])->toHaveCount(1)
        ->and($outcome['invalid_records'])->toBe([])
        ->and($new->record_status)->toBe('APPROVED.HISTORY')
        ->and($new->is_current)->toBeFalse()
        ->and($new->supersedes_id)->toBe($first->id)
        ->and($new->superseded_by_id)->toBe($second->id)
        ->and($first->fresh()->superseded_by_id)->toBe($new->id)
        ->and($second->fresh()->supersedes_id)->toBe($new->id)
        ->and($second->fresh()->is_current)->toBeTrue()
        ->and($second->fresh()->record_status)->toBe('APPROVED')
        ->and($result->fresh()->is_national_record)->toBeTrue();
});

it('setzt spätere, langsamere Rekorde auf INVALID und deren Rekord-Flag zurück', /** @throws Throwable */ function () {
    $base = base_rch();
    $laterResult = result_rch($base, '2015-05-01', 9500, 'Später');
    $laterResult->update(['is_national_record' => true]);
    [$first, $second] = chain_rch($base, $laterResult);
    $result = result_rch($base, '2012-05-01', 9400);

    $outcome = checkMeet_rch($result);
    $new = SwimRecord::query()->where('result_id', $result->id)->sole();
    $second->refresh();

    expect(collect($outcome['invalid_records'])->pluck('id')->all())->toBe([$second->id])
        ->and($second->record_status)->toBe('INVALID')
        ->and($second->is_current)->toBeFalse()
        ->and($second->supersedes_id)->toBeNull()
        ->and($second->comment)->toStartWith('Ungültig: älterer Rekord')
        ->and($laterResult->fresh()->is_national_record)->toBeFalse()
        ->and($new->record_status)->toBe('APPROVED')
        ->and($new->is_current)->toBeTrue()
        ->and($new->supersedes_id)->toBe($first->id)
        ->and($first->fresh()->superseded_by_id)->toBe($new->id);
});

it('setzt auch einen späteren, gleich schnellen Rekord auf INVALID', /** @throws Throwable */ function () {
    $base = base_rch();
    [, $second] = chain_rch($base);
    $result = result_rch($base, '2012-05-01', 9500);

    checkMeet_rch($result);

    expect($second->fresh()->record_status)->toBe('INVALID')
        ->and(SwimRecord::query()->where('result_id', $result->id)->sole()->is_current)->toBeTrue();
});

it('misst ein älteres Ergebnis am Rekord seines Wettkampftags, nicht am heutigen', /** @throws Throwable */ function () {
    $base = base_rch();
    chain_rch($base);
    // Langsamer als der Rekord von 2010 — kein Rekord, auch wenn es 2012 eingeordnet würde.
    $slower = result_rch($base, '2012-05-01', 10100);

    $outcome = checkMeet_rch($slower);

    expect($outcome['new_records'])->toBe([])
        ->and(SwimRecord::query()->where('result_id', $slower->id)->exists())->toBeFalse();
});

it('stellt mit einem Ergebnis vor dem ersten Rekord der Kette den damaligen Rekord auf', /** @throws Throwable */ function () {
    $base = base_rch();
    [$first] = chain_rch($base);
    $early = result_rch($base, '2008-05-01', 10100);

    checkMeet_rch($early);
    $new = SwimRecord::query()->where('result_id', $early->id)->sole();

    expect($new->record_status)->toBe('APPROVED.HISTORY')
        ->and($new->supersedes_id)->toBeNull()
        ->and($new->superseded_by_id)->toBe($first->id)
        ->and($first->fresh()->supersedes_id)->toBe($new->id)
        ->and(SwimRecord::query()->where('is_current', true)->count())->toBe(1);
});

it('vergleicht einen jüngeren Wettkampf wie bisher mit dem aktuellen Rekord', /** @throws Throwable */ function () {
    $base = base_rch();
    [, $second] = chain_rch($base);
    $result = result_rch($base, '2016-05-01', 9400);

    checkMeet_rch($result);
    $new = SwimRecord::query()->where('result_id', $result->id)->sole();

    expect($new->is_current)->toBeTrue()
        ->and($new->supersedes_id)->toBe($second->id)
        ->and($second->fresh()->record_status)->toBe('APPROVED.HISTORY')
        ->and($second->fresh()->superseded_by_id)->toBe($new->id);
});

it('ordnet einen bestätigten ausstehenden Rekord an seinem Datum ein', /** @throws Throwable */ function () {
    $base = base_rch();
    [$first, $second] = chain_rch($base);
    $pending = record_rch($base, '2012-05-01', 9400, 'PENDING');
    $pending->update(['is_current' => true, 'supersedes_id' => $first->id]);

    $pending->update(['record_status' => 'APPROVED']);
    $invalidated = $pending->approve();
    $pending->refresh();

    expect(collect($invalidated)->pluck('id')->all())->toBe([$second->id])
        ->and($second->fresh()->record_status)->toBe('INVALID')
        ->and($pending->record_status)->toBe('APPROVED')
        ->and($pending->is_current)->toBeTrue()
        ->and($pending->supersedes_id)->toBe($first->id);
});

it('schickt einen bestätigten Rekord in die Historie, wenn an seinem Datum ein schnellerer galt', /** @throws Throwable */ function () {
    $base = base_rch();
    [$first, $second] = chain_rch($base);
    $pending = record_rch($base, '2012-05-01', 10200, 'PENDING');

    $pending->update(['record_status' => 'APPROVED']);
    $invalidated = $pending->approve();
    $pending->refresh();

    expect($invalidated)->toBe([])
        ->and($pending->record_status)->toBe('APPROVED.HISTORY')
        ->and($pending->is_current)->toBeFalse()
        ->and($pending->superseded_by_id)->toBe($first->id)
        ->and($second->fresh()->record_status)->toBe('APPROVED');
});

it('zeigt ungültig gewordene Rekorde im Prüfergebnis des Rekord-Checks', function () {
    $base = base_rch();
    [, $second] = chain_rch($base);
    $result = result_rch($base, '2012-05-01', 9400);
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    $show = route('meets.show', $result->meet);

    $this->actingAs($admin)
        ->from($show)
        ->post(route('records.check', $result->meet))
        ->assertRedirect($show)
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 späterer Rekord dadurch ungültig'))
        ->assertSessionHas('record_check_result', fn (array $data): bool => count($data['invalid_record_ids']) === 1);

    $this->actingAs($admin)->get($show)
        ->assertSee('Ungültig gewordene Rekorde')
        ->assertSee($second->formatted_swim_time)
        ->assertSee('01.05.2015');
});

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
use App\Models\User;
use App\Services\AthleteMergeService;
use App\Services\RecordCheckerService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meet-records-check-status-p1');

// Prüfstatus des Rekordchecks je Veranstaltung (meets.records_checked_at): gesetzt nach jedem erfolgreichen Check,
// zurückgesetzt bei jeder rekordrelevanten Änderung an Ergebnissen dieser Veranstaltung.

function nation_mcs1(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function club_mcs1(string $name = 'Testverein'): Club
{
    return Club::firstOrCreate(['name' => $name], ['nation_id' => nation_mcs1()->id, 'type' => 'CLUB']);
}

function meet_mcs1(string $name = 'ÖSTM'): Meet
{
    return Meet::query()->create(['name' => $name, 'start_date' => '2026-05-05', 'end_date' => '2026-05-05',
        'course' => 'LCM', 'city' => 'Graz', 'nation_id' => nation_mcs1()->id, 'timing' => 'AUTOMATIC']);
}

function athlete_mcs1(string $lastName = 'Muster'): Athlete
{
    return Athlete::query()->create(['first_name' => 'Max', 'last_name' => $lastName, 'gender' => 'M',
        'birth_date' => '1990-01-01', 'nation_id' => nation_mcs1()->id, 'club_id' => club_mcs1()->id]);
}

function event_mcs1(Meet $meet, int $relayCount): SwimEvent
{
    $stroke = StrokeType::firstOrCreate(['lenex_code' => 'FREE'],
        ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE', 'is_active' => true]);

    return SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 100,
        'relay_count' => $relayCount, 'gender' => 'M', 'session_number' => 1,
        'event_number' => SwimEvent::query()->where('meet_id', $meet->id)->count() + 1, 'round' => 'TIM']);
}

function result_mcs1(Meet $meet, ?Athlete $athlete = null): Result
{
    return Result::query()->create(['meet_id' => $meet->id, 'swim_event_id' => event_mcs1($meet, 1)->id,
        'athlete_id' => ($athlete ?? athlete_mcs1())->id, 'club_id' => club_mcs1()->id, 'sport_class' => 'S9',
        'swim_time' => 7000, 'heat' => 1, 'lane' => 4]);
}

function relayResult_mcs1(Meet $meet): RelayResult
{
    return RelayResult::query()->create(['meet_id' => $meet->id, 'swim_event_id' => event_mcs1($meet, 4)->id,
        'club_id' => club_mcs1()->id, 'relay_number' => 1, 'gender' => 'M', 'relay_class' => '34',
        'swim_time' => 30000]);
}

/** Setzt den Status direkt, ohne Check — Ausgangslage "geprüft" für die Zurücksetzen-Tests. */
function markChecked_mcs1(Meet ...$meets): void
{
    foreach ($meets as $meet) {
        $meet->markRecordsChecked();
    }
}

function checkedAt_mcs1(Meet $meet): ?CarbonInterface
{
    return $meet->fresh()->records_checked_at;
}

// ── Setzen ────────────────────────────────────────────────────────────────────

it('ist für neue Veranstaltungen ungeprüft', function () {
    expect(meet_mcs1()->fresh()->records_checked_at)->toBeNull();
});

it('setzt den Status nach dem Check über den Knopf', function () {
    $meet = meet_mcs1();
    result_mcs1($meet);
    $this->travelTo('2026-11-11 10:15:00');

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->post(route('records.check', $meet))
        ->assertRedirect();

    expect(checkedAt_mcs1($meet)?->format('Y-m-d H:i:s'))->toBe('2026-11-11 10:15:00');
});

it('setzt den Status auch, wenn der Check ein Staffelergebnis ändert', /** @throws Throwable */ function () {
    $meet = meet_mcs1();
    relayResult_mcs1($meet);

    app(RecordCheckerService::class)->checkMeet($meet);

    expect(checkedAt_mcs1($meet))->not->toBeNull()
        ->and($meet->records_checked_at)->not->toBeNull();
});

it('setzt den Status über records:check-meets, aber nicht im Probelauf', function () {
    $meet = meet_mcs1();
    result_mcs1($meet);

    $this->artisan('records:check-meets', ['--dry-run' => true])->assertSuccessful();
    expect(checkedAt_mcs1($meet))->toBeNull();

    $this->artisan('records:check-meets')
        ->expectsConfirmation('1 Wettkampf/Wettkämpfe jetzt auf Rekorde prüfen?', 'yes')
        ->assertSuccessful();
    expect(checkedAt_mcs1($meet))->not->toBeNull();
});

it('markiert eine Veranstaltung ohne Ergebnisse nicht als geprüft', function () {
    $meet = meet_mcs1();
    event_mcs1($meet, 1);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->post(route('records.check', $meet))
        ->assertRedirect();

    expect(checkedAt_mcs1($meet))->toBeNull();
});

it('überspringt in records:check-meets Veranstaltungen ohne Ergebnisse', function () {
    $empty = meet_mcs1('Leer-Meeting');
    $withResults = meet_mcs1();
    result_mcs1($withResults);

    $this->artisan('records:check-meets')
        ->expectsConfirmation('1 Wettkampf/Wettkämpfe jetzt auf Rekorde prüfen?', 'yes')
        ->doesntExpectOutputToContain('Leer-Meeting')
        ->assertSuccessful();

    expect(checkedAt_mcs1($empty))->toBeNull()
        ->and(checkedAt_mcs1($withResults))->not->toBeNull();

    $this->artisan('records:check-meets', ['--meet' => $empty->id])
        ->expectsOutput('Wettkampf '.$empty->id.' nicht gefunden oder ohne Ergebnisse.')
        ->assertFailed();
});

it('setzt den Status nicht, wenn der Check fehlschlägt', function () {
    $meet = meet_mcs1();
    result_mcs1($meet);
    // Der Check bricht beim Anlegen des neuen Rekords ab.
    SwimRecord::creating(fn () => throw new RuntimeException('kaputt'));

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->post(route('records.check', $meet))
        ->assertSessionHas('error');

    expect(checkedAt_mcs1($meet))->toBeNull();
});

// ── Zurücksetzen: Einzelergebnisse ────────────────────────────────────────────

it('setzt den Status zurück, wenn ein Ergebnis angelegt oder gelöscht wird', function () {
    $meet = meet_mcs1();
    $other = meet_mcs1('Andere');
    markChecked_mcs1($meet, $other);

    $result = result_mcs1($meet);
    expect(checkedAt_mcs1($meet))->toBeNull()
        ->and(checkedAt_mcs1($other))->not->toBeNull();

    markChecked_mcs1($meet);
    $result->delete();
    expect(checkedAt_mcs1($meet))->toBeNull();
});

it('setzt den Status bei rekordrelevanten Änderungen zurück', function (string $field, mixed $value) {
    $meet = meet_mcs1();
    $result = result_mcs1($meet);
    markChecked_mcs1($meet);

    $result->update([$field => $value]);

    expect(checkedAt_mcs1($meet))->toBeNull();
})->with([
    'Zeit' => ['swim_time', 6900],
    'Status' => ['status', 'DSQ'],
    'Sportklasse' => ['sport_class', 'S8'],
    'Verein' => fn () => ['club_id', club_mcs1('Heimverein')->id],
]);

it('setzt den Status bei Punkten, Platz, Lauf/Bahn und Rekord-Flags nicht zurück', function () {
    $meet = meet_mcs1();
    $result = result_mcs1($meet);
    markChecked_mcs1($meet);

    $result->update(['points' => 512, 'wps_points' => 600, 'place' => 1, 'heat' => 2, 'lane' => 5,
        'comment' => 'Test', 'is_national_record' => true]);

    expect(checkedAt_mcs1($meet))->not->toBeNull();
});

it('setzt bei einem Wechsel der Veranstaltung beide zurück', function () {
    $meet = meet_mcs1();
    $other = meet_mcs1('Andere');
    $result = result_mcs1($meet);
    markChecked_mcs1($meet, $other);

    $result->update(['meet_id' => $other->id]);

    expect(checkedAt_mcs1($meet))->toBeNull()
        ->and(checkedAt_mcs1($other))->toBeNull();
});

it('setzt den Status bei Änderungen an Zwischenzeiten zurück', function () {
    $meet = meet_mcs1();
    $result = result_mcs1($meet);
    markChecked_mcs1($meet);

    $split = ResultSplit::query()->create(['result_id' => $result->id, 'distance' => 50, 'split_time' => 3300]);
    expect(checkedAt_mcs1($meet))->toBeNull();

    markChecked_mcs1($meet);
    $split->update(['split_time' => 3250]);
    expect(checkedAt_mcs1($meet))->toBeNull();
});

// ── Zurücksetzen: Staffeln ────────────────────────────────────────────────────

it('setzt den Status bei Staffelergebnissen, -mitgliedern und -zwischenzeiten zurück', function () {
    $meet = meet_mcs1();
    $relay = relayResult_mcs1($meet);
    markChecked_mcs1($meet);

    $relay->update(['swim_time' => 29900]);
    expect(checkedAt_mcs1($meet))->toBeNull();

    markChecked_mcs1($meet);
    $relay->update(['place' => 1, 'points' => 300]);
    expect(checkedAt_mcs1($meet))->not->toBeNull();

    $member = RelayResultMember::query()->create(['relay_result_id' => $relay->id, 'position' => 1,
        'athlete_id' => athlete_mcs1()->id]);
    expect(checkedAt_mcs1($meet))->toBeNull();

    markChecked_mcs1($meet);
    $member->update(['reaction_time' => 65]);
    expect(checkedAt_mcs1($meet))->not->toBeNull();

    $member->delete();
    expect(checkedAt_mcs1($meet))->toBeNull();

    markChecked_mcs1($meet);
    RelayResultSplit::query()->create(['relay_result_id' => $relay->id, 'distance' => 100, 'split_time' => 7500]);
    expect(checkedAt_mcs1($meet))->toBeNull();

    markChecked_mcs1($meet);
    $relay->delete();
    expect(checkedAt_mcs1($meet))->toBeNull();
});

// ── Zurücksetzen: Athleten zusammenführen ─────────────────────────────────────

it('setzt beim Zusammenführen von Athleten die Veranstaltungen ihrer Ergebnisse zurück', /** @throws Throwable */ function () {
    $keep = athlete_mcs1('Behalten');
    $drop = athlete_mcs1('Auflösen');
    $individual = meet_mcs1('Einzel');
    $relayMeet = meet_mcs1('Staffel');
    $untouched = meet_mcs1('Unbeteiligt');
    result_mcs1($individual, $drop);
    RelayResultMember::query()->create(['relay_result_id' => relayResult_mcs1($relayMeet)->id, 'position' => 1,
        'athlete_id' => $drop->id]);
    result_mcs1($untouched, $keep);
    markChecked_mcs1($individual, $relayMeet, $untouched);

    app(AthleteMergeService::class)->merge($keep, $drop, [], [], false);

    expect(checkedAt_mcs1($individual))->toBeNull()
        ->and(checkedAt_mcs1($relayMeet))->toBeNull()
        ->and(checkedAt_mcs1($untouched))->not->toBeNull();
});

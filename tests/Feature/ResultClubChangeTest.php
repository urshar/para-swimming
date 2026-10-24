<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\RecordCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('result-club-change-p3');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_rcc3(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function club_rcc3(?string $association): Club
{
    $club = makeClub_p5();
    $club->update(['regional_association' => $association]);

    return $club;
}

/** @return array{0: Meet, 1: SwimEvent, 2: Athlete, 3: Result} */
function setup_rcc3(Club $resultClub, Club $homeClub): array
{
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    $athlete = makeAthlete_p5($homeClub);
    $result = Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $resultClub->id, 'swim_time' => 6000, 'sport_class' => 'S9',
    ]);

    return [$meet, $event, $athlete, $result];
}

function check_rcc3(Meet $meet): void
{
    try {
        app(RecordCheckerService::class)->checkMeet($meet);
    } catch (Throwable $e) {
        throw new RuntimeException('Rekord-Check fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

function payload_rcc3(Result $result, Club $club): array
{
    return [
        'swim_event_id' => $result->swim_event_id,
        'athlete_id' => $result->athlete_id,
        'club_id' => $club->id,
        'swim_time' => '01:00.00',
        'sport_class' => 'S9',
    ];
}

function recordTypes_rcc3(Result $result): array
{
    return SwimRecord::where('result_id', $result->id)->pluck('record_type')->sort()->values()->all();
}

// ── Formular ──────────────────────────────────────────────────────────────────

it('bietet beim Bearbeiten alle Vereine an und belegt den Verein des Ergebnisses vor', function () {
    $national = club_rcc3(null);
    $wien = club_rcc3('WBSV');
    [, , , $result] = setup_rcc3($national, $wien);

    $response = $this->actingAs(admin_rcc3())
        ->get(route('results.edit', $result))
        ->assertOk()
        ->assertSee($wien->name)
        ->assertSee('die Landesrekorde werden neu geprüft');

    expect($response->viewData('clubs')->pluck('id')->all())->toContain($national->id, $wien->id);
});

// ── Vereinsänderung ───────────────────────────────────────────────────────────

it('zieht die Rekorde mit und legt nach der Korrektur den Landesrekord an', function () {
    $national = club_rcc3(null);
    $wien = club_rcc3('WBSV');
    [$meet, , , $result] = setup_rcc3($national, $wien);
    check_rcc3($meet);

    expect(recordTypes_rcc3($result))->toBe(['AUT']);

    $this->actingAs(admin_rcc3())
        ->put(route('results.update', $result), payload_rcc3($result, $wien))
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Neue Rekorde: AUT.WBSV'));

    $result->refresh();
    expect($result->club_id)->toBe($wien->id)
        ->and(recordTypes_rcc3($result))->toBe(['AUT', 'AUT.WBSV'])
        ->and(SwimRecord::where('result_id', $result->id)->pluck('club_id')->unique()->all())->toBe([$wien->id])
        ->and($result->is_national_record)->toBeTrue()
        ->and($result->is_regional_record)->toBeTrue();
});

it('prüft keine Rekorde, solange der Wettkampf noch nicht rekordgeprüft ist', function () {
    $national = club_rcc3(null);
    $wien = club_rcc3('WBSV');
    [, , , $result] = setup_rcc3($national, $wien);

    $this->actingAs(admin_rcc3())
        ->put(route('results.update', $result), payload_rcc3($result, $wien))
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Rekord-Check für diesen Wettkampf ist noch nicht gelaufen'));

    expect(SwimRecord::count())->toBe(0)
        ->and($result->refresh()->club_id)->toBe($wien->id);
});

it('lässt Landesrekorde eines anderen Landesverbands stehen und meldet sie', function () {
    $vorarlberg = club_rcc3('VBSV');
    $wien = club_rcc3('WBSV');
    [$meet, , , $result] = setup_rcc3($vorarlberg, $wien);
    check_rcc3($meet);

    $this->actingAs(admin_rcc3())
        ->put(route('results.update', $result), payload_rcc3($result, $wien))
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'anderen Landesverbands (AUT.VBSV)'));

    expect(recordTypes_rcc3($result))->toBe(['AUT', 'AUT.VBSV', 'AUT.WBSV'])
        ->and(SwimRecord::where('result_id', $result->id)->where('record_type', 'AUT.VBSV')->sole()->club_id)
        ->toBe($vorarlberg->id)
        ->and(SwimRecord::where('result_id', $result->id)->where('record_type', 'AUT')->sole()->club_id)
        ->toBe($wien->id);
});

it('prüft bei unverändertem Verein nichts nach', function () {
    $national = club_rcc3(null);
    [$meet, , , $result] = setup_rcc3($national, club_rcc3('WBSV'));
    check_rcc3($meet);

    $this->actingAs(admin_rcc3())
        ->put(route('results.update', $result), payload_rcc3($result, $national))
        ->assertSessionHas('success', 'Ergebnis aktualisiert.');

    expect(recordTypes_rcc3($result))->toBe(['AUT']);
});

// ── Rekord-Flags beim erneuten Prüfen ─────────────────────────────────────────

it('behält das ÖR-Flag beim erneuten Rekord-Check der Veranstaltung', function () {
    $wien = club_rcc3('WBSV');
    [$meet, , , $result] = setup_rcc3($wien, $wien);
    check_rcc3($meet);
    $result->update(['is_regional_record' => false]);

    check_rcc3($meet);

    $result->refresh();
    expect($result->is_national_record)->toBeTrue()
        ->and($result->is_regional_record)->toBeTrue()
        ->and(recordTypes_rcc3($result))->toBe(['AUT', 'AUT.WBSV']);
});

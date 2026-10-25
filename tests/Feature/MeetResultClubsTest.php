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

uses(RefreshDatabase::class)->group('result-clubs-p4');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_rcp4(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function club_rcp4(?string $association, ?string $type = null): Club
{
    $club = makeClub_p5();
    $club->update(array_filter(['regional_association' => $association, 'type' => $type]));

    return $club;
}

function result_rcp4(Meet $meet, SwimEvent $event, Athlete $athlete, Club $club, int $time): Result
{
    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'swim_time' => $time, 'sport_class' => 'S9',
    ]);
}

function check_rcp4(Meet $meet): void
{
    try {
        app(RecordCheckerService::class)->checkMeet($meet);
    } catch (Throwable $e) {
        throw new RuntimeException('Rekord-Check fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

// ── Seite ─────────────────────────────────────────────────────────────────────

it('listet die Athleten mit Heimverein und weist auf Ergebnisse unter einem Verband hin', function () {
    $national = club_rcp4(null, Club::TYPE_VERBAND);
    $wien = club_rcp4('WBSV');
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    $athlete = makeAthlete_p5($wien);
    result_rcp4($meet, $event, $athlete, $national, 6000);

    $response = $this->actingAs(admin_rcp4())
        ->get(route('meets.result-clubs', $meet))
        ->assertOk()
        ->assertSee($athlete->display_name)
        ->assertSee($wien->name)
        ->assertSee('Heimverein übernehmen')
        ->assertSee('1 Ergebnis steht unter einem');

    expect($response->viewData('federationCount'))->toBe(1);
});

it('bietet bei einem späteren Vereinswechsel keinen Heimverein an', function () {
    $earlier = club_rcp4('SBSV');
    $now = club_rcp4('SBSV');
    $meet = makeMeet_p5();
    result_rcp4($meet, makeEvent_p5($meet, ['event_number' => 1]), makeAthlete_p5($now), $earlier, 6000);

    $response = $this->actingAs(admin_rcp4())
        ->get(route('meets.result-clubs', $meet))
        ->assertOk()
        ->assertDontSee('Heimverein übernehmen');

    expect($response->viewData('federationCount'))->toBe(0)
        ->and($response->viewData('rows')->sole()['underFederation'])->toBeFalse();
});

it('verlinkt die Seite aus der Ergebnis-Sammelansicht', function () {
    $meet = makeMeet_p5();

    $this->actingAs(admin_rcp4())
        ->get(route('meets.results-overview', $meet))
        ->assertOk()
        ->assertSee(route('meets.result-clubs', $meet));
});

// ── Je Athlet ─────────────────────────────────────────────────────────────────

it('setzt den Verein aller Einzelergebnisse eines Athleten und prüft die Landesrekorde nach', function () {
    $national = club_rcp4(null, Club::TYPE_VERBAND);
    $wien = club_rcp4('WBSV');
    $meet = makeMeet_p5();
    $free = makeEvent_p5($meet, ['event_number' => 1]);
    $back = makeEvent_p5($meet, ['event_number' => 2, 'stroke_type_id' => makeStrokeType_p5('BACK')->id]);
    $athlete = makeAthlete_p5($wien);
    $other = makeAthlete_p5($wien);
    $a = result_rcp4($meet, $free, $athlete, $national, 6000);
    $b = result_rcp4($meet, $back, $athlete, $national, 7000);
    $untouched = result_rcp4($meet, $back, $other, $national, 8000);
    check_rcp4($meet);

    $this->actingAs(admin_rcp4())
        ->put(route('meets.result-clubs.update', $meet), ['athlete_id' => $athlete->id, 'club_id' => $wien->id])
        ->assertRedirect(route('meets.result-clubs', $meet))
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '2 Ergebnisse geändert')
            && str_contains($message, 'Neue Rekorde: AUT.WBSV (2)'));

    expect($a->refresh()->club_id)->toBe($wien->id)
        ->and($b->refresh()->club_id)->toBe($wien->id)
        ->and($untouched->refresh()->club_id)->toBe($national->id)
        ->and(SwimRecord::where('record_type', 'AUT.WBSV')->pluck('result_id')->sort()->values()->all())
        ->toBe([$a->id, $b->id])
        ->and(SwimRecord::where('result_id', $a->id)->where('record_type', 'AUT')->sole()->club_id)->toBe($wien->id);
});

it('lehnt einen Athleten ohne Ergebnis in diesem Wettkampf ab', function () {
    $meet = makeMeet_p5();
    $wien = club_rcp4('WBSV');
    $stranger = makeAthlete_p5($wien);

    $this->actingAs(admin_rcp4())
        ->from(route('meets.result-clubs', $meet))
        ->put(route('meets.result-clubs.update', $meet), ['athlete_id' => $stranger->id, 'club_id' => $wien->id])
        ->assertSessionHasErrors('athlete_id');
});

// ── Verband → Heimverein ──────────────────────────────────────────────────────

it('setzt nur Ergebnisse unter einem Verband auf den Heimverein', function () {
    $national = club_rcp4(null, Club::TYPE_VERBAND);
    $kaernten = club_rcp4('KBSV');
    $wien = club_rcp4('WBSV');
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    $fromNational = result_rcp4($meet, $event, makeAthlete_p5($kaernten), $national, 6000);
    $ownClub = result_rcp4($meet, $event, makeAthlete_p5($kaernten), $wien, 6100);
    $federationHome = result_rcp4($meet, $event, makeAthlete_p5($national), $national, 6200);

    $this->actingAs(admin_rcp4())
        ->post(route('meets.result-clubs.home-clubs', $meet))
        ->assertRedirect(route('meets.result-clubs', $meet))
        ->assertSessionHas('success', fn (string $message): bool => str_starts_with($message, '1 Ergebnis geändert')
            && str_contains($message, 'Rekord-Check für diesen Wettkampf ist noch nicht gelaufen'));

    expect($fromNational->refresh()->club_id)->toBe($kaernten->id)
        ->and($ownClub->refresh()->club_id)->toBe($wien->id)
        ->and($federationHome->refresh()->club_id)->toBe($national->id)
        ->and(SwimRecord::count())->toBe(0);
});

it('lässt beim Nachprüfen je Kategorie nur das schnellste Ergebnis den Landesrekord aufstellen', function () {
    $national = club_rcp4(null, Club::TYPE_VERBAND);
    $wien = club_rcp4('WBSV');
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    // Langsameres Ergebnis zuerst angelegt: Die Reihenfolge darf nicht von der ID abhängen.
    $slow = result_rcp4($meet, $event, makeAthlete_p5($wien), $national, 6500);
    $fast = result_rcp4($meet, $event, makeAthlete_p5($wien), $national, 6000);
    check_rcp4($meet);

    $this->actingAs(admin_rcp4())
        ->post(route('meets.result-clubs.home-clubs', $meet))
        ->assertSessionHas('success', fn (string $message): bool => str_ends_with($message, 'Neue Rekorde: AUT.WBSV.'));

    expect(SwimRecord::where('record_type', 'AUT.WBSV')->pluck('result_id')->all())->toBe([$fast->id])
        ->and($slow->refresh()->is_regional_record)->toBeFalse()
        ->and($fast->refresh()->is_regional_record)->toBeTrue();
});

<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ImportReviewItem;
use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('record-pending-review');

// Prüfliste Rekorde, Reiter "Ausstehende Rekorde" (docs/specs/records.md "Prüfliste Rekorde").
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_rpr(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** @return array{0: Meet, 1: Club, 2: Athlete, 3: SwimEvent, 4: SwimEvent} */
function setup_rpr(): array
{
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    $relayEvent = makeEvent_p5($meet, ['event_number' => 2, 'relay_count' => 4]);

    return [$meet, $club, $athlete, $event, $relayEvent];
}

function record_rpr(SwimEvent $event, array $attributes): SwimRecord
{
    return SwimRecord::create([
        'stroke_type_id' => $event->stroke_type_id,
        'record_type' => 'AUT',
        'sport_class' => 'S9',
        'gender' => 'M',
        'course' => 'LCM',
        'distance' => $event->distance,
        'relay_count' => 1,
        'swim_time' => 6000,
        'record_status' => 'PENDING',
        'is_current' => true,
        'set_date' => '2026-05-01',
        ...$attributes,
    ]);
}

/** Je ein ausstehender Rekord pro Grund, dazu ein bestätigter und ein überbotener ausstehender (PENDING.HISTORY). */
function pendingSet_rpr(): array
{
    [$meet, $club, $athlete, $event, $relayEvent] = setup_rpr();

    $exhResult = Result::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'swim_time' => 6000, 'sport_class' => 'S9', 'status' => 'EXH']);
    $relay = RelayResult::create(['meet_id' => $meet->id, 'swim_event_id' => $relayEvent->id, 'club_id' => $club->id,
        'gender' => 'M', 'relay_class' => 'S14', 'swim_time' => 24000]);

    return [
        'exhibition' => record_rpr($event, ['athlete_id' => $athlete->id, 'result_id' => $exhResult->id,
            'meet_name' => 'AK-Meeting']),
        'split' => record_rpr($event, ['athlete_id' => $athlete->id, 'from_split' => true, 'swim_time' => 3000,
            'comment' => 'Zwischenzeit aus 100 m', 'meet_name' => 'Split-Meeting']),
        'club' => record_rpr($relayEvent, ['relay_count' => 4, 'sport_class' => 'S14', 'relay_result_id' => $relay->id,
            'swim_time' => 24000, 'meet_name' => 'Staffel-Meeting']),
        'other' => record_rpr($event, ['athlete_id' => $athlete->id, 'course' => 'SCM', 'meet_name' => 'Hand-Meeting']),
        'approved' => record_rpr($event, ['record_status' => 'APPROVED', 'gender' => 'F', 'meet_name' => 'Bestätigt-Meeting']),
        'history' => record_rpr($event, ['record_status' => 'PENDING.HISTORY', 'is_current' => false,
            'meet_name' => 'Historie-Meeting']),
    ];
}

it('listet alle ausstehenden Rekorde mit Grund, ohne bestätigte und überbotene', function () {
    pendingSet_rpr();

    $this->actingAs(admin_rpr())
        ->get(route('records.import-review.pending'))
        ->assertOk()
        ->assertSee('Prüfliste Rekorde')
        ->assertSee('AK-Meeting')
        ->assertSee('Split-Meeting')
        ->assertSee('Staffel-Meeting')
        ->assertSee('Hand-Meeting')
        ->assertSee('Außer Konkurrenz')
        ->assertSee('Vereinszugehörigkeit')
        ->assertDontSee('Bestätigt-Meeting')
        ->assertDontSee('Historie-Meeting');
});

it('leitet den Grund eines ausstehenden Rekords her', function () {
    $set = pendingSet_rpr();

    $reasons = collect($set)->except(['approved', 'history'])
        ->map(fn (SwimRecord $record) => $record->fresh(['result', 'relayResult'])->pending_reason)
        ->all();

    expect($reasons)->toBe([
        'exhibition' => SwimRecord::PENDING_REASON_EXHIBITION,
        'split' => SwimRecord::PENDING_REASON_SPLIT,
        'club' => SwimRecord::PENDING_REASON_CLUB,
        'other' => SwimRecord::PENDING_REASON_OTHER,
    ]);
});

it('filtert nach Grund und nach Einzel/Staffel', function (string $reason, string $kind, array $expected) {
    $set = pendingSet_rpr();

    $ids = SwimRecord::pending()->pendingReason($reason)
        ->when($kind === 'relay', fn ($q) => $q->where('relay_count', '>', 1))
        ->when($kind === 'single', fn ($q) => $q->where('relay_count', 1))
        ->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect($expected)->map(fn (string $key) => $set[$key]->id)->sort()->values()->all());

    $response = $this->actingAs(admin_rpr())
        ->get(route('records.import-review.pending', ['reason' => $reason, 'kind' => $kind]))
        ->assertOk();
    foreach (array_diff(['exhibition', 'split', 'club', 'other'], $expected) as $hidden) {
        $response->assertDontSee($set[$hidden]->meet_name);
    }
    foreach ($expected as $shown) {
        $response->assertSee($set[$shown]->meet_name);
    }
})->with([
    'außer Konkurrenz' => ['exhibition', 'all', ['exhibition']],
    'Zwischenzeit' => ['split', 'all', ['split']],
    'Vereinszugehörigkeit' => ['club', 'all', ['club']],
    'Sonstige' => ['other', 'all', ['other']],
    'nur Staffeln' => ['all', 'relay', ['club']],
    'nur Einzel' => ['all', 'single', ['exhibition', 'split', 'other']],
]);

it('bestätigt einen ausstehenden Rekord aus der Prüfliste und kehrt dorthin zurück', function () {
    $set = pendingSet_rpr();
    $url = route('records.import-review.pending');

    $this->actingAs(admin_rpr())
        ->from($url)
        ->patch(route('records.status.update', $set['other']), ['record_status' => 'APPROVED'])
        ->assertRedirect($url);

    expect($set['other']->fresh()->record_status)->toBe('APPROVED')
        ->and(SwimRecord::pending()->count())->toBe(3);
});

it('verwirft einen ausstehenden Rekord als ungültig', function () {
    $set = pendingSet_rpr();

    $this->actingAs(admin_rpr())
        ->from(route('records.import-review.pending'))
        ->patch(route('records.status.update', $set['split']), ['record_status' => 'INVALID']);

    expect($set['split']->fresh()->record_status)->toBe('INVALID')
        ->and(SwimRecord::pending()->count())->toBe(3);
});

it('zeigt die Anzahl je Reiter und die Summe im Menü', function () {
    pendingSet_rpr();
    ImportReviewItem::create(['type' => ImportReviewItem::TYPE_RELAY_NO_CLUB, 'status' => ImportReviewItem::STATUS_OPEN,
        'source' => 'Test', 'details' => ['label' => 'Testeintrag']]);

    $this->actingAs(admin_rpr())
        ->get(route('records.import-review.index'))
        ->assertOk()
        ->assertSeeInOrder(['Import-Befunde', '1', 'Ausstehende Rekorde', '4'])
        ->assertSeeInOrder(['Prüfliste', '5']);
});

it('führt im Menü auf die ausstehenden Rekorde, wenn es keine offenen Import-Befunde gibt', function () {
    pendingSet_rpr();

    $this->actingAs(admin_rpr())
        ->get(route('records.index'))
        ->assertOk()
        ->assertSee(route('records.import-review.pending'))
        ->assertDontSee('Import Prüfliste');
});

it('zeigt neben dem ausstehenden den am Datum geltenden Rekord mit Abstand', function () {
    [, , $athlete, $event] = setup_rpr();
    $athlete->update(['first_name' => 'Vera', 'last_name' => 'Vorgängerin']);

    record_rpr($event, ['athlete_id' => $athlete->id, 'record_status' => 'APPROVED.HISTORY', 'is_current' => false,
        'swim_time' => 6250, 'set_date' => '2025-03-01']);
    record_rpr($event, ['record_status' => 'APPROVED', 'swim_time' => 5900, 'set_date' => '2026-08-01']);
    record_rpr($event, ['swim_time' => 6100, 'set_date' => '2026-05-01', 'meet_name' => 'Prüf-Meeting']);
    record_rpr($event, ['course' => 'SCM', 'meet_name' => 'Ohne-Vorgänger-Meeting']);

    $this->actingAs(admin_rpr())
        ->get(route('records.import-review.pending'))
        ->assertOk()
        ->assertSeeInOrder(['1:01.00', 'bisher', '1:02.50', '-1.50 s', 'Vorgängerin', '01.03.2025', 'Prüf-Meeting'])
        ->assertDontSee('0:59.00')
        ->assertSee('bisher kein Rekord');
});

it('zeigt einen Hinweis, wenn nichts aussteht', function () {
    $this->actingAs(admin_rpr())
        ->get(route('records.import-review.pending'))
        ->assertOk()
        ->assertSee('Keine ausstehenden Rekorde.');
});

it('verweigert Vereinsnutzern die ausstehenden Rekorde', function () {
    $club = makeClub_p5();

    $this->actingAs(User::factory()->create(['is_admin' => false, 'club_id' => $club->id]))
        ->get(route('records.import-review.pending'))
        ->assertForbidden();
});

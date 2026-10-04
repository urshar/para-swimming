<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\LenexExportService;
use App\Services\MeetEntryListService;
use App\Services\RecordCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('exhibition-entries');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeAthlete_p5(), makeEvent_p5().

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_ak(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function clubUser_ak(Club $club): User
{
    return User::factory()->create(['is_admin' => false, 'club_id' => $club->id]);
}

/** Offene Veranstaltung mit Verein, Athlet (S9), Einzel- und Staffelbewerb. */
function setup_ak(): array
{
    $meet = makeMeet_p5();
    $meet->forceFill(['is_open' => true])->save();
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    $relayEvent = makeEvent_p5($meet, ['event_number' => 2, 'relay_count' => 4]);

    return [$meet, $club, $athlete, $event, $relayEvent];
}

function result_ak(Meet $meet, SwimEvent $event, Athlete $athlete, int $time, ?string $status): Result
{
    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => $time,
        'sport_class' => 'S9',
        'status' => $status,
    ]);
}

function record_ak(SwimEvent $event, string $type, int $time, string $status): SwimRecord
{
    return SwimRecord::create([
        'stroke_type_id' => $event->stroke_type_id,
        'record_type' => $type,
        'sport_class' => 'S9',
        'gender' => 'M',
        'course' => 'LCM',
        'distance' => $event->distance,
        'relay_count' => 1,
        'swim_time' => $time,
        'record_status' => $status,
        'is_current' => true,
    ]);
}

/** LENEX-Export (DOMException → RuntimeException, Muster wie lenex_mf1()). */
function lenex_ak(Meet $meet): SimpleXMLElement
{
    try {
        return simplexml_load_string((new LenexExportService)->build($meet, 'entries'));
    } catch (DOMException $e) {
        throw new RuntimeException('LENEX-Export fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

/** Rekord-Check einer Veranstaltung (Throwable → RuntimeException). */
function check_ak(Meet $meet): array
{
    try {
        return app(RecordCheckerService::class)->checkMeet($meet);
    } catch (Throwable $e) {
        throw new RuntimeException('Rekord-Check fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

/** Ausstehenden Rekord bestätigen wie RecordController: Status APPROVED, dann approve() (Throwable → RuntimeException). */
function approve_ak(SwimRecord $record): void
{
    $record->update(['record_status' => 'APPROVED']);

    try {
        $record->approve();
    } catch (Throwable $e) {
        throw new RuntimeException('Bestätigen fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

// ── Meldungen setzen ──────────────────────────────────────────────────────────

it('setzt AK bei einer Einzelmeldung des Vereins als Status EXH und nimmt es wieder zurück', function () {
    [$meet, $club, $athlete, $event] = setup_ak();
    $user = clubUser_ak($club);

    $this->actingAs($user)
        ->post(route('club-entries.store', $meet), ['swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'exhibition' => '1'])
        ->assertRedirect();
    $entry = Entry::first();

    expect($entry->status)->toBe('EXH');

    $this->actingAs($user)
        ->put(route('club-entries.update', [$meet, $entry]), ['entry_time' => '1:10.00'])
        ->assertRedirect();

    expect($entry->fresh()->status)->toBeNull();
});

it('lässt beim Abhaken andere Status als EXH unangetastet', function () {
    [$meet, $club, $athlete, $event] = setup_ak();
    $entry = Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id, 'status' => 'WDR']);

    $this->actingAs(clubUser_ak($club))
        ->put(route('club-entries.update', [$meet, $entry]), ['entry_time' => '1:10.00'])
        ->assertRedirect();

    expect($entry->fresh()->status)->toBe('WDR');
});

it('setzt AK bei einer Staffelmeldung und nimmt es wieder zurück', function () {
    [$meet, $club, , , $relayEvent] = setup_ak();
    $user = clubUser_ak($club);

    $this->actingAs($user)
        ->post(route('club-entries.relay.store', $meet), ['swim_event_id' => $relayEvent->id, 'exhibition' => '1'])
        ->assertRedirect();
    $relay = RelayEntry::first();

    expect($relay->is_exhibition)->toBeTrue();

    $this->actingAs($user)
        ->put(route('club-entries.relay.update', [$meet, $relay]), ['entry_time' => '4:30.00'])
        ->assertRedirect();

    expect($relay->fresh()->is_exhibition)->toBeFalse();
});

it('zeigt die Checkbox in den Melde-Formularen vorbelegt', function () {
    [$meet, $club, $athlete, $event, $relayEvent] = setup_ak();
    $entry = Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id, 'status' => 'EXH']);
    $relay = RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => $relayEvent->id, 'club_id' => $club->id, 'is_exhibition' => true]);
    $user = clubUser_ak($club);

    $this->actingAs($user)->get(route('club-entries.create', $meet))->assertOk()->assertSee('Außer Konkurrenz (AK)');
    $this->actingAs($user)->get(route('club-entries.relay.create', $meet))->assertOk()->assertSee('Außer Konkurrenz (AK)');

    foreach ([route('club-entries.edit', [$meet, $entry]), route('club-entries.relay.edit', [$meet, $relay])] as $url) {
        expect($this->actingAs($user)->get($url)->assertOk()->getContent())
            ->toMatch('/<ui-checkbox[^>]*checked="checked"[^>]*name="exhibition"/');
    }
});

// ── Anzeige ───────────────────────────────────────────────────────────────────

it('kennzeichnet AK in den Meldungslisten und Meldelisten', function () {
    [$meet, $club, $athlete, $event, $relayEvent] = setup_ak();
    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id, 'status' => 'EXH']);
    RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => $relayEvent->id, 'club_id' => $club->id, 'is_exhibition' => true]);
    $admin = admin_ak();

    $this->actingAs($admin)->get(route('club-entries.index', ['meet' => $meet, 'club_id' => $club->id]))
        ->assertOk()->assertSee('title="Außer Konkurrenz"', false);
    $this->actingAs($admin)->get(route('club-entries.relay.index', ['meet' => $meet, 'club_id' => $club->id]))
        ->assertOk()->assertSee('title="Außer Konkurrenz"', false);
    expect(substr_count($this->actingAs($admin)->get(route('meets.entries-overview', $meet))->getContent(), 'title="Außer Konkurrenz"'))
        ->toBe(2);

    $blocks = app(MeetEntryListService::class)->byEvent($meet)->flatMap(fn (array $s) => $s['events']);
    expect($blocks->firstWhere('isRelay', false)['entrants'][0]['name'])->toEndWith(' (AK)')
        ->and($blocks->firstWhere('isRelay', true)['relays'][0]['name'])->toEndWith(' (AK)');
});

// ── LENEX ─────────────────────────────────────────────────────────────────────

it('exportiert AK bei Einzel- und Staffelmeldungen als ENTRY status EXH', function () {
    [$meet, $club, $athlete, $event, $relayEvent] = setup_ak();
    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id, 'status' => 'EXH']);
    RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => $relayEvent->id, 'club_id' => $club->id, 'is_exhibition' => true]);

    $clubXml = lenex_ak($meet)->MEETS->MEET->CLUBS->CLUB;

    expect((string) $clubXml->ATHLETES->ATHLETE->ENTRIES->ENTRY['status'])->toBe('EXH')
        ->and((string) $clubXml->RELAYS->RELAY->ENTRIES->ENTRY['status'])->toBe('EXH');
});

// ── Ergebniserfassung ─────────────────────────────────────────────────────────

it('gibt der Ergebniserfassung die AK-Meldungen zur Vorbelegung mit', function () {
    [$meet, $club, $athlete, $event] = setup_ak();
    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id, 'status' => 'EXH']);

    $this->actingAs(admin_ak())->get(route('meets.results.create', $meet))
        ->assertOk()
        ->assertSee('exhibitionKeys: ["'.$event->id.'-'.$athlete->id.'"]', false);
});

// ── Rekorde ───────────────────────────────────────────────────────────────────

it('legt aus einem AK-Ergebnis alle Rekordtypen als ausstehend an', function () {
    [$meet, $club, $athlete, $event] = setup_ak();
    $club->update(['regional_association' => 'WBSV']);
    $athlete->update(['birth_date' => '2010-01-01']);
    $result = result_ak($meet, $event, $athlete, 6000, 'EXH');

    $check = check_ak($meet);

    expect($check['new_records'])->toBeEmpty()
        ->and(collect($check['pending_records'])->pluck('type')->all())
        ->toEqualCanonicalizing(['AUT', 'AUT.JR', 'AUT.WBSV', 'AUT.WBSV.JR'])
        ->and(collect($check['pending_records'])->pluck('reason')->unique()->all())
        ->toBe([RecordCheckerService::PENDING_EXHIBITION])
        ->and(SwimRecord::where('record_status', 'PENDING')->count())->toBe(4)
        ->and($result->fresh()->is_national_record)->toBeFalse();

    // Erneuter Check legt nichts doppelt an.
    check_ak($meet);
    expect(SwimRecord::count())->toBe(4);
});

it('löst beim Bestätigen den bisherigen Rekord ab und setzt das Rekord-Flag', function () {
    [$meet, , $athlete, $event] = setup_ak();
    $old = record_ak($event, 'AUT', 6500, 'APPROVED');
    $result = result_ak($meet, $event, $athlete, 6000, 'EXH');
    check_ak($meet);
    $pending = SwimRecord::where('record_status', 'PENDING')->where('record_type', 'AUT')->sole();

    expect($old->fresh()->is_current)->toBeTrue();

    $this->actingAs(admin_ak())
        ->patch(route('records.status.update', $pending), ['record_status' => 'APPROVED'])
        ->assertRedirect();

    expect($pending->fresh()->record_status)->toBe('APPROVED')
        ->and($pending->fresh()->is_current)->toBeTrue()
        ->and($old->fresh()->is_current)->toBeFalse()
        ->and($old->fresh()->record_status)->toBe('APPROVED.HISTORY')
        ->and($old->fresh()->superseded_by_id)->toBe($pending->id)
        ->and($result->fresh()->is_national_record)->toBeTrue();
});

it('misst reguläre Ergebnisse am geltenden, nicht am ausstehenden Rekord', function () {
    [$meet, $club, $athlete, $event] = setup_ak();
    record_ak($event, 'AUT', 6500, 'APPROVED');
    result_ak($meet, $event, $athlete, 6000, 'EXH');
    $other = makeAthlete_p5($club);
    result_ak($meet, $event, $other, 6200, null);

    $check = check_ak($meet);
    $approved = SwimRecord::where('swim_time', 6200)->sole();

    expect(collect($check['new_records'])->pluck('types')->flatten()->all())->toBe(['AUT'])
        ->and($approved->record_status)->toBe('APPROVED');

    // Der schnellere AK-Rekord löst beim Bestätigen den inzwischen geltenden 62,00-Rekord ab.
    $pending = SwimRecord::where('swim_time', 6000)->sole();
    approve_ak($pending);

    expect($approved->fresh()->is_current)->toBeFalse()
        ->and($pending->fresh()->is_current)->toBeTrue();
});

it('legt einen bestätigten, aber inzwischen unterbotenen AK-Rekord direkt in die Historie', function () {
    [, , , $event] = setup_ak();
    $pending = record_ak($event, 'AUT', 6000, 'PENDING');
    $faster = record_ak($event, 'AUT', 5900, 'APPROVED');

    approve_ak($pending);

    expect($pending->fresh()->is_current)->toBeFalse()
        ->and($pending->fresh()->record_status)->toBe('APPROVED.HISTORY')
        ->and($pending->fresh()->superseded_by_id)->toBe($faster->id)
        ->and($faster->fresh()->is_current)->toBeTrue();
});

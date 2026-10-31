<?php

use App\Models\Athlete;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Entry;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\User;
use App\Services\AthleteClubTransferService;
use App\Services\AthleteMergeService;
use App\Services\AuditLogger;
use App\Support\AuditContext;
use App\Support\AuditHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('club-permissions-p4');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_cp4(): User
{
    return User::factory()->create(['is_admin' => true, 'name' => 'Admin Eins']);
}

/** @return list<AuditLog> */
function logsFor_cp4(object $subject): array
{
    return AuditLog::where('auditable_type', $subject::class)->where('auditable_id', $subject->id)
        ->orderBy('id')->get()->all();
}

function entry_cp4(Athlete $athlete): Entry
{
    $meet = makeMeet_p5();

    return Entry::create([
        'meet_id' => $meet->id,
        'swim_event_id' => makeEvent_p5($meet)->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'entry_time' => 6543,
    ]);
}

function relay_cp4(Club $club): RelayEntry
{
    $meet = makeMeet_p5();

    return RelayEntry::create([
        'meet_id' => $meet->id,
        'swim_event_id' => makeEvent_p5($meet, ['relay_count' => 4, 'distance' => 50])->id,
        'club_id' => $club->id,
    ]);
}

/** @param  list<Athlete>  $athletes */
function setMembers_cp4(RelayEntry $relay, array $athletes): void
{
    $audit = app(AuditLogger::class);
    $before = $audit->relayMemberNames($relay);
    $relay->members()->delete();
    foreach ($athletes as $pos => $athlete) {
        RelayEntryMember::create(['relay_entry_id' => $relay->id, 'athlete_id' => $athlete->id, 'position' => $pos + 1]);
    }
    $audit->relayMembersChanged($relay, $before);
}

// ── Model-Events ──────────────────────────────────────────────────────────────

it('protokolliert das Anlegen eines Athleten mit allen gesetzten Feldern', function () {
    $this->actingAs(admin_cp4());
    $athlete = makeAthlete_p5(makeClub_p5());

    $log = logsFor_cp4($athlete)[0];

    expect($log->event)->toBe('created')
        ->and($log->user_name)->toBe('Admin Eins')
        ->and($log->source)->toBe(AuditLog::SOURCE_WEB)
        ->and($log->athlete_id)->toBe($athlete->id)
        ->and($log->club_id)->toBe($athlete->club_id)
        ->and($log->changes['last_name'])->toBe([null, $athlete->last_name])
        ->and($log->changes)->not->toHaveKeys(['id', 'created_at', 'updated_at']);
});

it('protokolliert beim Ändern nur die geänderten Felder, ohne Änderung nichts', function () {
    $athlete = makeAthlete_p5(makeClub_p5());
    $this->actingAs(admin_cp4());

    $athlete->update(['phone' => '0664 123', 'first_name' => 'Max']);
    $athlete->update(['phone' => '0664 123']);
    $athlete->touch();

    $logs = logsFor_cp4($athlete);

    expect($logs)->toHaveCount(2)
        ->and($logs[1]->event)->toBe('updated')
        ->and($logs[1]->changes)->toBe(['phone' => [null, '0664 123']]);
});

it('protokolliert Ändern über das Athletenformular mit Benutzer und Quelle web', function () {
    $athlete = makeAthlete_p5(makeClub_p5());
    $admin = admin_cp4();

    $this->actingAs($admin)->put(route('athletes.update', $athlete), [
        'first_name' => 'Max',
        'last_name' => $athlete->last_name,
        'gender' => 'M',
        'nation_id' => $athlete->nation_id,
        'club_id' => $athlete->club_id,
        'birth_date' => '2001-02-03',
        'is_active' => true,
    ])->assertRedirect();

    $log = AuditLog::where('event', 'updated')->where('auditable_id', $athlete->id)->sole();

    expect($log->user_id)->toBe($admin->id)
        ->and($log->source)->toBe(AuditLog::SOURCE_WEB)
        ->and($log->changes)->toHaveKey('birth_date');
});

it('protokolliert Löschen mit den alten Werten und Wiederherstellen', function () {
    $club = makeClub_p5();

    $club->delete();
    $club->restore();

    $logs = logsFor_cp4($club);

    expect(array_map(fn (AuditLog $log) => $log->event, $logs))->toBe(['created', 'deleted', 'restored'])
        ->and($logs[1]->changes['name'])->toBe([$club->name, null])
        ->and($logs[1]->club_id)->toBe($club->id)
        ->and($logs[0]->user_id)->toBeNull()
        ->and($logs[0]->source)->toBe(AuditLog::SOURCE_CONSOLE);
});

it('ordnet Einzelmeldungen dem Athleten und Staffelmeldungen dem Verein zu', function () {
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $entry = entry_cp4($athlete);
    relay_cp4($club);

    $athleteTypes = AuditLog::forAthlete($athlete)->pluck('auditable_type')->unique()->sort()->values()->all();
    $clubTypes = AuditLog::forClub($club)->pluck('auditable_type')->unique()->sort()->values()->all();

    expect($athleteTypes)->toBe([Athlete::class, Entry::class])
        ->and($clubTypes)->toBe([Club::class, RelayEntry::class])
        ->and(logsFor_cp4($entry)[0]->athlete_id)->toBe($athlete->id);
});

// ── Einträge über AuditLogger ─────────────────────────────────────────────────

it('protokolliert die Staffelbesetzung als einen Eintrag "Mitglieder"', function () {
    $club = makeClub_p5();
    [$a, $b, $c] = [makeAthlete_p5($club), makeAthlete_p5($club), makeAthlete_p5($club)];
    $relay = relay_cp4($club);

    setMembers_cp4($relay, [$a, $b]);
    setMembers_cp4($relay, [$a, $c]);
    setMembers_cp4($relay, [$a, $c]);

    $logs = array_values(array_filter(logsFor_cp4($relay), fn (AuditLog $log) => isset($log->changes['members'])));

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->changes['members'])->toBe([null, $a->last_name.' Max, '.$b->last_name.' Max'])
        ->and($logs[1]->changes['members'])->toBe([$a->last_name.' Max, '.$b->last_name.' Max', $a->last_name.' Max, '.$c->last_name.' Max'])
        ->and(RelayEntryMember::count())->toBe(2);
});

it('protokolliert einen Vereinswechsel als einen Eintrag', /** @throws Throwable */ function () {
    $from = makeClub_p5();
    $to = makeClub_p5();
    $athlete = makeAthlete_p5($from);

    app(AthleteClubTransferService::class)->transfer($athlete, $to->id, '2026-09-01', null);

    $logs = logsFor_cp4($athlete);

    expect(array_map(fn (AuditLog $log) => $log->event, $logs))->toBe(['created', 'transferred'])
        ->and($logs[1]->changes)->toBe(['club_id' => [$from->id, $to->id], 'joined_at' => [null, '2026-09-01']])
        ->and($athlete->fresh()->club_id)->toBe($to->id);
});

it('protokolliert das Zusammenführen und nimmt den Meldungsverlauf mit', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $keep = makeAthlete_p5($club);
    $drop = makeAthlete_p5($club);
    $entry = entry_cp4($drop);

    app(AthleteMergeService::class)->merge($keep, $drop, [], [], false);

    $merged = AuditLog::where('auditable_id', $keep->id)->where('event', 'merged')->sole();

    expect($merged->changes['merged_athlete'][1])->toContain('#'.$drop->id)
        ->and(AuditLog::where('auditable_type', Athlete::class)->where('auditable_id', $drop->id)->where('event', 'deleted')->exists())->toBeTrue()
        ->and(AuditLog::forAthlete($keep)->where('auditable_type', Entry::class)->where('auditable_id', $entry->id)->exists())->toBeTrue();
});

it('setzt während eines Imports Quelle und Dateiname', function () {
    $context = app(AuditContext::class);

    $imported = $context->during(AuditLog::SOURCE_IMPORT, 'LENEX: meldung.lef', fn () => makeClub_p5());
    $later = makeClub_p5();

    expect(logsFor_cp4($imported)[0]->source)->toBe(AuditLog::SOURCE_IMPORT)
        ->and(logsFor_cp4($imported)[0]->source_detail)->toBe('LENEX: meldung.lef')
        ->and(logsFor_cp4($later)[0]->source)->toBe(AuditLog::SOURCE_CONSOLE)
        ->and(logsFor_cp4($later)[0]->source_detail)->toBeNull();
});

it('behält den Namen, wenn der Benutzer gelöscht wird', function () {
    $user = User::factory()->create(['name' => 'Weg Gegangen']);
    $this->actingAs($user);
    $club = makeClub_p5();

    $user->delete();

    $log = logsFor_cp4($club)[0];

    expect($log->user_id)->toBeNull()
        ->and($log->user_name)->toBe('Weg Gegangen');
});

// ── Anzeige ───────────────────────────────────────────────────────────────────

it('zeigt Admins den Verlauf mit lesbaren Feldnamen und Werten', function () {
    $from = makeClub_p5();
    $to = makeClub_p5();
    $athlete = makeAthlete_p5($from);
    $athlete->update(['is_active' => false, 'next_medical_check_at' => '2027-03-04']);
    $athlete->update(['club_id' => $to->id]);

    $this->actingAs(admin_cp4())
        ->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee('Verlauf')
        ->assertSee('Nächste med. Kontrolle')
        ->assertSee('04.03.2027')
        ->assertSee('Aktiv')
        ->assertSee('Nein')
        ->assertSee($to->display_name)
        ->assertSee('System');
});

it('zeigt den Vereinsverlauf mit Staffelmeldungen samt Bewerb und Wettkampf', function () {
    $club = makeClub_p5();
    relay_cp4($club);

    $this->actingAs(admin_cp4())
        ->get(route('clubs.show', $club))
        ->assertOk()
        ->assertSee('Verlauf')
        ->assertSee('Staffelmeldung: 4x50m')
        ->assertSee('Testmeet');
});

it('zeigt Vereinsnutzern keinen Verlauf', function () {
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $user = User::factory()->create(['club_id' => $club->id]);

    $this->actingAs($user)->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertDontSee('id="verlauf"', false);
    $this->actingAs($user)->get(route('clubs.show', $club))
        ->assertOk()
        ->assertDontSee('id="verlauf"', false);
});

it('begrenzt den Verlauf auf 50 Einträge mit "Mehr anzeigen"', function () {
    $club = makeClub_p5();
    foreach (range(1, AuditHistory::LIMIT + 2) as $i) {
        $club->update(['short_name' => 'K'.$i]);
    }

    $short = AuditHistory::build(AuditLog::forClub($club), false);
    $all = AuditHistory::build(AuditLog::forClub($club), true);

    expect($short['entries'])->toHaveCount(AuditHistory::LIMIT)
        ->and($short['has_more'])->toBeTrue()
        ->and($all['entries'])->toHaveCount(AuditHistory::LIMIT + 3)
        ->and($all['has_more'])->toBeFalse()
        ->and($short['entries'][0]['changes'][0])->toBe(['label' => 'Kurzname', 'old' => 'K'.(AuditHistory::LIMIT + 1), 'new' => 'K'.(AuditHistory::LIMIT + 2)]);
});

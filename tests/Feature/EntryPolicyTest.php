<?php

// Dateien kopieren nach:
// tests/Feature/EntryPolicyTest.php

use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeNation(): Nation
{
    return Nation::firstOrCreate(
        ['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]
    );
}

function makeMeet(array $attrs = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Testbewerb',
        'start_date' => Carbon::today()->addDays(14)->toDateString(),
        'course' => 'LCM',
        'city' => 'Wien',
        'nation_id' => makeNation()->id,
    ], $attrs));
}

// makeAdmin und makeClub sind in UserManagementTest.php definiert —
// hier neu definiert damit der Test standalone läuft.
// Pest lädt Helper-Funktionen global, daher mit _p2 suffix um Konflikte zu vermeiden.

function makeAdmin_p2(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'is_admin' => true,
        'club_id' => null,
    ], $attrs));
}

function makeClub_p2(array $attrs = []): Club
{
    return Club::factory()->create($attrs);
}

function makeClubUser_p2(Club $club, array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'is_admin' => false,
        'club_id' => $club->id,
    ], $attrs));
}

// ═══════════════════════════════════════════════════════════════════════════
// Admin — darf immer
// ═══════════════════════════════════════════════════════════════════════════

describe('Admin Berechtigungen', function () {

    it('Admin darf Meldungen verwalten ohne Deadline', function () {
        $admin = makeAdmin_p2();
        $meet = makeMeet();

        expect(Gate::forUser($admin)->allows('manageEntries', $meet))->toBeTrue();
    });

    it('Admin darf Meldungen verwalten wenn Deadline abgelaufen', function () {
        $admin = makeAdmin_p2();
        $meet = makeMeet(['entries_deadline' => Carbon::yesterday()->toDateString()]);

        expect(Gate::forUser($admin)->allows('manageEntries', $meet))->toBeTrue();
    });

    it('Admin darf Meldungen verwalten wenn Deadline heute ist', function () {
        $admin = makeAdmin_p2();
        $meet = makeMeet(['entries_deadline' => Carbon::today()->toDateString()]);

        expect(Gate::forUser($admin)->allows('manageEntries', $meet))->toBeTrue();
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// Vereins-User ohne Deadline
// ═══════════════════════════════════════════════════════════════════════════

describe('Vereins-User ohne Meldeschluss', function () {

    it('Vereins-User darf Meldungen verwalten wenn keine Deadline gesetzt', function () {
        $club = makeClub_p2();
        $user = makeClubUser_p2($club);
        $meet = makeMeet(['entries_deadline' => null]);

        expect(Gate::forUser($user)->allows('manageEntries', $meet))->toBeTrue();
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// Vereins-User mit Deadline
// ═══════════════════════════════════════════════════════════════════════════

describe('Vereins-User mit Meldeschluss', function () {

    it('Vereins-User darf Meldungen verwalten wenn Deadline in der Zukunft liegt', function () {
        $club = makeClub_p2();
        $user = makeClubUser_p2($club);
        $meet = makeMeet(['entries_deadline' => Carbon::tomorrow()->toDateString()]);

        expect(Gate::forUser($user)->allows('manageEntries', $meet))->toBeTrue();
    });

    it('Vereins-User darf Meldungen verwalten wenn Deadline heute ist', function () {
        $club = makeClub_p2();
        $user = makeClubUser_p2($club);
        $meet = makeMeet(['entries_deadline' => Carbon::today()->toDateString()]);

        expect(Gate::forUser($user)->allows('manageEntries', $meet))->toBeTrue();
    });

    it('Vereins-User darf NICHT Meldungen verwalten wenn Deadline gestern war', function () {
        $club = makeClub_p2();
        $user = makeClubUser_p2($club);
        $meet = makeMeet(['entries_deadline' => Carbon::yesterday()->toDateString()]);

        expect(Gate::forUser($user)->allows('manageEntries', $meet))->toBeFalse();
    });

    it('Vereins-User darf NICHT Meldungen verwalten wenn Deadline vor einer Woche war', function () {
        $club = makeClub_p2();
        $user = makeClubUser_p2($club);
        $meet = makeMeet(['entries_deadline' => Carbon::today()->subWeek()->toDateString()]);

        expect(Gate::forUser($user)->allows('manageEntries', $meet))->toBeFalse();
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// User ohne Verein
// ═══════════════════════════════════════════════════════════════════════════

describe('User ohne Verein', function () {

    it('User ohne Club darf nie Meldungen verwalten', function () {
        $user = User::factory()->create(['is_admin' => false, 'club_id' => null]);
        $meet = makeMeet(['entries_deadline' => null]);

        expect(Gate::forUser($user)->allows('manageEntries', $meet))->toBeFalse();
    });

    it('User ohne Club darf auch mit zukünftiger Deadline nicht melden', function () {
        $user = User::factory()->create(['is_admin' => false, 'club_id' => null]);
        $meet = makeMeet(['entries_deadline' => Carbon::tomorrow()->toDateString()]);

        expect(Gate::forUser($user)->allows('manageEntries', $meet))->toBeFalse();
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// Meet Hilfsmethoden
// ═══════════════════════════════════════════════════════════════════════════

describe('Meet Hilfsmethoden', function () {

    it('isDeadlinePassed() gibt false zurück wenn keine Deadline gesetzt', function () {
        $meet = makeMeet(['entries_deadline' => null]);
        expect($meet->isDeadlinePassed())->toBeFalse();
    });

    it('isDeadlinePassed() gibt false zurück wenn Deadline heute ist', function () {
        $meet = makeMeet(['entries_deadline' => Carbon::today()->toDateString()]);
        expect($meet->isDeadlinePassed())->toBeFalse();
    });

    it('isDeadlinePassed() gibt false zurück wenn Deadline in der Zukunft liegt', function () {
        $meet = makeMeet(['entries_deadline' => Carbon::tomorrow()->toDateString()]);
        expect($meet->isDeadlinePassed())->toBeFalse();
    });

    it('isDeadlinePassed() gibt true zurück wenn Deadline gestern war', function () {
        $meet = makeMeet(['entries_deadline' => Carbon::yesterday()->toDateString()]);
        expect($meet->isDeadlinePassed())->toBeTrue();
    });

    it('hasDeadline() gibt false zurück wenn keine Deadline gesetzt', function () {
        $meet = makeMeet(['entries_deadline' => null]);
        expect($meet->hasDeadline())->toBeFalse();
    });

    it('hasDeadline() gibt true zurück wenn Deadline gesetzt ist', function () {
        $meet = makeMeet(['entries_deadline' => Carbon::tomorrow()->toDateString()]);
        expect($meet->hasDeadline())->toBeTrue();
    });

});

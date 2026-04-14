<?php

use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Feature-Test Helpers ──────────────────────────────────────────────────────

function makeAdminUser_p5(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_p5(Club $club): User
{
    return User::factory()->create(['is_admin' => false, 'club_id' => $club->id]);
}

function makeOpenMeet_p5(array $attrs = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Offenes Meet',
        'nation_id' => makeNation_p5()->id,
        'course' => 'LCM',
        'start_date' => now()->addDays(30)->toDateString(),
        'is_open' => true,
    ], $attrs));
}

// ── Index ─────────────────────────────────────────────────────────────────────

describe('index', function () {

    it('Club-User sieht eigene Meldungen', function () {
        $club = makeClub_p5();
        $user = makeClubUser_p5($club);
        $meet = makeOpenMeet_p5();
        $event = makeEvent_p5($meet, ['gender' => 'M']);
        $athlete = makeAthlete_p5($club, 'M', ['S9']);

        Entry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id,
            'club_id' => $club->id,
        ]);

        $this->actingAs($user)
            ->get(route('club-entries.index', $meet))
            ->assertOk()
            ->assertSee($athlete->last_name);
    });

    it('User ohne Club bekommt 403', function () {
        $user = User::factory()->create(['is_admin' => false, 'club_id' => null]);
        $meet = makeOpenMeet_p5();

        $this->actingAs($user)
            ->get(route('club-entries.index', $meet))
            ->assertForbidden();
    });

})->group('club-entry-feature');

// ── Store ─────────────────────────────────────────────────────────────────────

describe('store', function () {

    it('Club-User kann Meldung anlegen', function () {
        $club = makeClub_p5();
        $user = makeClubUser_p5($club);
        $meet = makeOpenMeet_p5();
        $event = makeEvent_p5($meet, ['gender' => 'M', 'sport_classes' => '9']);
        $athlete = makeAthlete_p5($club, 'M', ['S9']);

        $this->actingAs($user)
            ->post(route('club-entries.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_id' => $athlete->id,
                'entry_time' => '01:05.23',
                'entry_course' => 'LCM',
            ])
            ->assertRedirect(route('club-entries.index', $meet));

        expect(Entry::where([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id,
            'club_id' => $club->id,
        ])->exists())->toBeTrue();
    });

    it('NT wird als entry_time_code gespeichert', function () {
        $club = makeClub_p5();
        $user = makeClubUser_p5($club);
        $meet = makeOpenMeet_p5();
        $event = makeEvent_p5($meet, ['gender' => 'M']);
        $athlete = makeAthlete_p5($club, 'M', ['S9']);

        $this->actingAs($user)
            ->post(route('club-entries.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_id' => $athlete->id,
                'entry_time' => 'NT',
            ]);

        $entry = Entry::where('athlete_id', $athlete->id)->first();
        expect($entry->entry_time)->toBeNull();
        expect($entry->entry_time_code)->toBe('NT');
    });

    it('nach Meldeschluss ist Store nicht erlaubt', function () {
        $club = makeClub_p5();
        $user = makeClubUser_p5($club);
        $meet = makeOpenMeet_p5(['entries_deadline' => now()->subDay()->toDateString()]);
        $event = makeEvent_p5($meet, ['gender' => 'M']);
        $athlete = makeAthlete_p5($club, 'M', ['S9']);

        $this->actingAs($user)
            ->post(route('club-entries.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_id' => $athlete->id,
            ])
            ->assertForbidden();
    });

    it('Admin darf nach Meldeschluss melden', function () {
        $admin = makeAdminUser_p5();
        $meet = makeOpenMeet_p5(['entries_deadline' => now()->subDay()->toDateString()]);
        // Admin nutzt ersten Club
        $club = makeClub_p5();
        $event = makeEvent_p5($meet, ['gender' => 'M']);
        $athlete = makeAthlete_p5($club, 'M', ['S9']);

        $this->actingAs($admin)
            ->post(route('club-entries.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_id' => $athlete->id,
            ])
            ->assertRedirect(route('club-entries.index', $meet));
    });

    it('Athlet aus fremdem Club wird abgelehnt', function () {
        $ownClub = makeClub_p5();
        $foreignClub = makeClub_p5();
        $user = makeClubUser_p5($ownClub);
        $meet = makeOpenMeet_p5();
        $event = makeEvent_p5($meet, ['gender' => 'M']);
        $foreign = makeAthlete_p5($foreignClub, 'M', ['S9']);

        $this->actingAs($user)
            ->post(route('club-entries.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_id' => $foreign->id,
            ])
            ->assertStatus(404); // findOrFail schlägt fehl
    });

})->group('club-entry-feature');

// ── Update ────────────────────────────────────────────────────────────────────

describe('update', function () {

    it('Meldezeit kann aktualisiert werden', function () {
        $club = makeClub_p5();
        $user = makeClubUser_p5($club);
        $meet = makeOpenMeet_p5();
        $event = makeEvent_p5($meet);
        $athlete = makeAthlete_p5($club);

        $entry = Entry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id,
            'club_id' => $club->id,
            'entry_time' => 6000,
        ]);

        $this->actingAs($user)
            ->put(route('club-entries.update', [$meet, $entry]), [
                'entry_time' => '01:05.00',
                'entry_course' => 'LCM',
            ])
            ->assertRedirect(route('club-entries.index', $meet));

        expect($entry->fresh()->entry_time)->toBe(6500);
    });

})->group('club-entry-feature');

// ── Destroy ───────────────────────────────────────────────────────────────────

describe('destroy', function () {

    it('Club-User kann eigene Meldung löschen', function () {
        $club = makeClub_p5();
        $user = makeClubUser_p5($club);
        $meet = makeOpenMeet_p5();
        $event = makeEvent_p5($meet);
        $athlete = makeAthlete_p5($club);

        $entry = Entry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id,
            'club_id' => $club->id,
        ]);

        $this->actingAs($user)
            ->delete(route('club-entries.destroy', [$meet, $entry]))
            ->assertRedirect(route('club-entries.index', $meet));

        expect(Entry::find($entry->id))->toBeNull();
    });

    it('fremde Meldung kann nicht gelöscht werden', function () {
        $ownClub = makeClub_p5();
        $foreignClub = makeClub_p5();
        $user = makeClubUser_p5($ownClub);
        $meet = makeOpenMeet_p5();
        $event = makeEvent_p5($meet);
        $athlete = makeAthlete_p5($foreignClub);

        $entry = Entry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id,
            'club_id' => $foreignClub->id,
        ]);

        $this->actingAs($user)
            ->delete(route('club-entries.destroy', [$meet, $entry]))
            ->assertForbidden();
    });

})->group('club-entry-feature');

// ── AJAX ──────────────────────────────────────────────────────────────────────

describe('AJAX eligible-athletes', function () {

    it('gibt passende Athleten als JSON zurück', function () {
        $club = makeClub_p5();
        $user = makeClubUser_p5($club);
        $meet = makeOpenMeet_p5();
        $event = makeEvent_p5($meet, ['gender' => 'M', 'sport_classes' => '9']);

        $a1 = makeAthlete_p5($club, 'M', ['S9']);
        makeAthlete_p5($club, 'F', ['S9']); // falsches Geschlecht

        $this->actingAs($user)
            ->getJson(route('club-entries.eligible-athletes', $meet).'?event_id='.$event->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $a1->id);
    });

})->group('club-entry-feature');

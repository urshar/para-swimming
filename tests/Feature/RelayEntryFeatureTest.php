<?php

use App\Models\Athlete;
use App\Models\AthleteSportClass;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeNation_p6(): Nation
{
    static $nation = null;
    if ($nation && Nation::find($nation->id)) {
        return $nation;
    }
    $nation = Nation::forceCreate([
        'code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
    ]);

    return $nation;
}

function makeClub_p6(): Club
{
    return Club::create([
        'name' => 'Testverein '.uniqid(),
        'nation_id' => makeNation_p6()->id,
        'type' => 'CLUB',
    ]);
}

function makeStroke_p6(): StrokeType
{
    static $stroke = null;
    if ($stroke && StrokeType::find($stroke->id)) {
        return $stroke;
    }
    $stroke = StrokeType::create([
        'name_de' => 'Freistil',
        'name_en' => 'Freestyle',
        'lenex_code' => 'FREE',
        'code' => 'FREE',
    ]);

    return $stroke;
}

function makeClubUser_p6(Club $club): User
{
    return User::factory()->create(['is_admin' => false, 'club_id' => $club->id]);
}

function makeOpenMeet_p6(array $attrs = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Testbewerb',
        'nation_id' => makeNation_p6()->id,
        'course' => 'LCM',
        'start_date' => now()->addDays(30)->toDateString(),
        'is_open' => true,
    ], $attrs));
}

function makeRelayEvent_p6(Meet $meet, array $attrs = []): SwimEvent
{
    return SwimEvent::create(array_merge([
        'meet_id' => $meet->id,
        'stroke_type_id' => makeStroke_p6()->id,
        'distance' => 100,
        'relay_count' => 4,
        'gender' => 'M',
        'session_number' => 1,
        'event_number' => 1,
        'round' => 'TIM',
        'sport_classes' => '1 2 3 4 5 6 7 8 9 10',
    ], $attrs));
}

function makeRelayAthlete_p6(Club $club, string $gender = 'M', string $sportClass = 'S6'): Athlete
{
    preg_match('/^(SB|SM|S)(\d+)$/', $sportClass, $m);
    $category = $m[1] ?? 'S';
    $number = $m[2] ?? '6';

    $athlete = Athlete::create([
        'first_name' => 'Test',
        'last_name' => 'Athlet '.uniqid(),
        'gender' => $gender,
        'birth_date' => '2000-06-01',
        'nation_id' => makeNation_p6()->id,
        'club_id' => $club->id,
    ]);

    AthleteSportClass::create([
        'athlete_id' => $athlete->id,
        'category' => $category,
        'class_number' => $number,
        'sport_class' => $sportClass,
    ]);

    return $athlete;
}

// ── Index ─────────────────────────────────────────────────────────────────────

describe('indexRelay', function () {

    it('Club-User sieht eigene Staffelmeldungen', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet);

        RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
            'relay_class' => 'S20',
        ]);

        $this->actingAs($user)
            ->get(route('club-entries.relay.index', $meet))
            ->assertOk()
            ->assertSee('S20');
    });

    it('User ohne Club bekommt 403', function () {
        $user = User::factory()->create(['is_admin' => false, 'club_id' => null]);
        $meet = makeOpenMeet_p6();

        $this->actingAs($user)
            ->get(route('club-entries.relay.index', $meet))
            ->assertForbidden();
    });

    it('Staffelmeldungen anderer Clubs sind nicht sichtbar', function () {
        $ownClub = makeClub_p6();
        $foreignClub = makeClub_p6();
        $user = makeClubUser_p6($ownClub);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet);

        RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $foreignClub->id,
            'relay_class' => 'S34',
        ]);

        $response = $this->actingAs($user)
            ->get(route('club-entries.relay.index', $meet));

        $response->assertOk();
        // Eigener Club hat 0 Meldungen — fremde S34 darf nicht auftauchen
        $response->assertDontSee('S34');
    });

})->group('relay-entry-feature');

// ── Store ─────────────────────────────────────────────────────────────────────

describe('storeRelay', function () {

    it('Staffelmeldung mit 4 Athleten kann angelegt werden', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet, ['relay_count' => 4]);

        // S4 + S5 + S6 + S5 → Summe 20 → relay_class = S20
        $a1 = makeRelayAthlete_p6($club, 'M', 'S4');
        $a2 = makeRelayAthlete_p6($club, 'M', 'S5');
        $a3 = makeRelayAthlete_p6($club, 'M', 'S6');
        $a4 = makeRelayAthlete_p6($club, 'M', 'S5');

        $this->actingAs($user)
            ->post(route('club-entries.relay.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_ids' => [$a1->id, $a2->id, $a3->id, $a4->id],
                'entry_time' => '04:30.00',
                'entry_course' => 'LCM',
            ])
            ->assertRedirect(route('club-entries.relay.index', $meet));

        $relay = RelayEntry::where('meet_id', $meet->id)->where('club_id', $club->id)->first();
        expect($relay)
            ->not->toBeNull()
            ->and($relay->relay_class)->toBe('S20')
            ->and($relay->members()->count())->toBe(4);
    });

    it('relay_class S49 wird für Visual-Staffel berechnet', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet, ['relay_count' => 4, 'sport_classes' => '11 12 13']);

        $a1 = makeRelayAthlete_p6($club, 'M', 'S11');
        $a2 = makeRelayAthlete_p6($club, 'M', 'S12');
        $a3 = makeRelayAthlete_p6($club, 'M', 'S13');
        $a4 = makeRelayAthlete_p6($club, 'M', 'S11');

        $this->actingAs($user)
            ->post(route('club-entries.relay.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_ids' => [$a1->id, $a2->id, $a3->id, $a4->id],
            ])
            ->assertRedirect(route('club-entries.relay.index', $meet));

        expect(RelayEntry::where('club_id', $club->id)->first()->relay_class)->toBe('S49');
    });

    it('NT wird als entry_time_code gespeichert', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet);

        $athletes = collect(range(1, 4))->map(fn () => makeRelayAthlete_p6($club, 'M', 'S5'));

        $this->actingAs($user)
            ->post(route('club-entries.relay.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_ids' => $athletes->pluck('id')->toArray(),
                'entry_time' => 'NT',
            ]);

        $relay = RelayEntry::where('club_id', $club->id)->first();
        expect($relay->entry_time)->toBeNull()
            ->and($relay->entry_time_code)->toBe('NT');
    });

    it('zu viele Athleten werden abgelehnt', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet, ['relay_count' => 4]);

        $athletes = collect(range(1, 5))->map(fn () => makeRelayAthlete_p6($club, 'M', 'S5'));

        $this->actingAs($user)
            ->post(route('club-entries.relay.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_ids' => $athletes->pluck('id')->toArray(),
            ])
            ->assertSessionHasErrors('athlete_ids');
    });

    it('Athlet aus fremdem Club wird abgelehnt', function () {
        $ownClub = makeClub_p6();
        $foreignClub = makeClub_p6();
        $user = makeClubUser_p6($ownClub);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet);

        $foreign = makeRelayAthlete_p6($foreignClub, 'M', 'S5');

        $this->actingAs($user)
            ->post(route('club-entries.relay.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_ids' => [$foreign->id],
            ])
            ->assertStatus(404);
    });

    it('nach Meldeschluss ist Store nicht erlaubt', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6(['entries_deadline' => now()->subDay()->toDateString()]);
        $event = makeRelayEvent_p6($meet);
        $a1 = makeRelayAthlete_p6($club, 'M', 'S5');

        $this->actingAs($user)
            ->post(route('club-entries.relay.store', $meet), [
                'swim_event_id' => $event->id,
                'athlete_ids' => [$a1->id],
            ])
            ->assertForbidden();
    });

})->group('relay-entry-feature');

// ── Update ────────────────────────────────────────────────────────────────────

describe('updateRelay', function () {

    it('Athleten können ausgetauscht und relay_class neu berechnet werden', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet, ['relay_count' => 4]);

        // Initiale Meldung: S20 (Summe = 20)
        $old = collect(range(1, 4))->map(fn () => makeRelayAthlete_p6($club, 'M', 'S5'));
        $relay = RelayEntry::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id,
            'club_id' => $club->id, 'relay_class' => 'S20',
        ]);
        foreach ($old as $i => $a) {
            RelayEntryMember::create([
                'relay_entry_id' => $relay->id,
                'athlete_id' => $a->id,
                'position' => $i + 1,
                'sport_class' => 'S5',
            ]);
        }

        // Update: neue Athleten S11/S12/S13/S11 → S49
        $a1 = makeRelayAthlete_p6($club, 'M', 'S11');
        $a2 = makeRelayAthlete_p6($club, 'M', 'S12');
        $a3 = makeRelayAthlete_p6($club, 'M', 'S13');
        $a4 = makeRelayAthlete_p6($club, 'M', 'S11');

        $this->actingAs($user)
            ->put(route('club-entries.relay.update', [$meet, $relay]), [
                'athlete_ids' => [$a1->id, $a2->id, $a3->id, $a4->id],
                'entry_time' => '04:00.00',
                'entry_course' => 'LCM',
            ])
            ->assertRedirect(route('club-entries.relay.index', $meet));

        $relay->refresh();
        expect($relay->relay_class)->toBe('S49')
            ->and($relay->members()->count())->toBe(4)
            // Alte Members müssen weg sein
            ->and($relay->members()->whereIn('athlete_id', $old->pluck('id'))->count())->toBe(0);
    });

    it('fremde RelayEntry kann nicht bearbeitet werden', function () {
        $ownClub = makeClub_p6();
        $foreignClub = makeClub_p6();
        $user = makeClubUser_p6($ownClub);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet);

        $relay = RelayEntry::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id,
            'club_id' => $foreignClub->id,
        ]);

        $own = makeRelayAthlete_p6($ownClub, 'M', 'S5');

        $this->actingAs($user)
            ->put(route('club-entries.relay.update', [$meet, $relay]), [
                'athlete_ids' => [$own->id],
            ])
            ->assertForbidden();
    });

})->group('relay-entry-feature');

// ── Destroy ───────────────────────────────────────────────────────────────────

describe('destroyRelay', function () {

    it('Staffelmeldung kann gelöscht werden', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet);

        $relay = RelayEntry::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        $this->actingAs($user)
            ->delete(route('club-entries.relay.destroy', [$meet, $relay]))
            ->assertRedirect(route('club-entries.relay.index', $meet));

        expect(RelayEntry::find($relay->id))->toBeNull();
    });

    it('fremde Staffelmeldung kann nicht gelöscht werden', function () {
        $ownClub = makeClub_p6();
        $foreignClub = makeClub_p6();
        $user = makeClubUser_p6($ownClub);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet);

        $relay = RelayEntry::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id,
            'club_id' => $foreignClub->id,
        ]);

        $this->actingAs($user)
            ->delete(route('club-entries.relay.destroy', [$meet, $relay]))
            ->assertForbidden();
    });

    it('Members werden mit der RelayEntry gelöscht (cascadeOnDelete)', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet);

        $relay = RelayEntry::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);
        $athlete = makeRelayAthlete_p6($club, 'M', 'S5');
        RelayEntryMember::create([
            'relay_entry_id' => $relay->id,
            'athlete_id' => $athlete->id,
            'position' => 1,
        ]);

        $this->actingAs($user)
            ->delete(route('club-entries.relay.destroy', [$meet, $relay]));

        expect(RelayEntryMember::where('relay_entry_id', $relay->id)->count())->toBe(0);
    });

})->group('relay-entry-feature');

// ── AJAX ──────────────────────────────────────────────────────────────────────

describe('AJAX relay-athletes', function () {

    it('gibt Athleten des Clubs passend zum Geschlecht zurück', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6();
        $event = makeRelayEvent_p6($meet, ['gender' => 'M']);

        $male = makeRelayAthlete_p6($club, 'M', 'S6');
        makeRelayAthlete_p6($club, 'F', 'S6'); // falsches Geschlecht

        $this->actingAs($user)
            ->getJson(route('club-entries.relay.relay-athletes', $meet).'?event_id='.$event->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $male->id);
    });

    it('gibt 404 wenn event_id zu einem Einzel-Event gehört', function () {
        $club = makeClub_p6();
        $user = makeClubUser_p6($club);
        $meet = makeOpenMeet_p6();

        $singleEvent = SwimEvent::create([
            'meet_id' => $meet->id, 'stroke_type_id' => makeStroke_p6()->id,
            'distance' => 100, 'relay_count' => 1, 'gender' => 'M',
            'session_number' => 1, 'event_number' => 1, 'round' => 'TIM',
        ]);

        $this->actingAs($user)
            ->getJson(route('club-entries.relay.relay-athletes', $meet).'?event_id='.$singleEvent->id)
            ->assertNotFound();
    });

})->group('relay-entry-feature');

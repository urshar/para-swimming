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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function relayBase(): array
{
    $nation = Nation::forceCreate([
        'code' => 'AUT',
        'name_de' => 'Österreich',
        'name_en' => 'Austria',
        'is_active' => true,
    ]);

    $club = Club::create([
        'name' => 'Testverein',
        'nation_id' => $nation->id,
        'type' => 'CLUB',
    ]);

    $meet = Meet::create([
        'name' => 'Test Meet',
        'start_date' => '2025-06-01',
        'end_date' => '2025-06-01',
        'course' => 'LCM',
        'city' => 'Wien',
        'nation_id' => $nation->id,
    ]);

    $stroke = StrokeType::create([
        'name_de' => 'Freistil',
        'name_en' => 'Freestyle',
        'lenex_code' => 'FREE',
        'code' => 'FREE',
    ]);

    $event = SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => $stroke->id,
        'distance' => 100,
        'relay_count' => 4,
        'gender' => 'M',
        'session_number' => 1,
        'event_number' => 1,
        'round' => 'TIM',
    ]);

    return compact('nation', 'club', 'meet', 'stroke', 'event');
}

function makeAthlete(Nation $nation, Club $club, string $sportClass = 'S9'): Athlete
{
    preg_match('/^(SB|SM|S)(\d+)$/', $sportClass, $m);

    $athlete = Athlete::create([
        'first_name' => 'Max',
        'last_name' => 'Muster',
        'gender' => 'M',
        'birth_date' => '2000-01-01',
        'nation_id' => $nation->id,
        'club_id' => $club->id,
    ]);

    AthleteSportClass::create([
        'athlete_id' => $athlete->id,
        'category' => $m[1] ?? 'S',
        'class_number' => $m[2] ?? '9',
        'sport_class' => $sportClass,
    ]);

    return $athlete;
}

// ── RelayEntry: Erstellung & Relationen ───────────────────────────────────────

describe('RelayEntry', function () {

    it('kann angelegt werden', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
            'relay_class' => 'S20',
            'status' => 'pending',
        ]);

        expect($entry->id)->toBeInt()
            ->and($entry->relay_class)->toBe('S20')
            ->and($entry->status)->toBe('pending');
    })->group('relay-entry');

    it('hat Relation zu Meet, SwimEvent und Club', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        expect($entry->meet->id)->toBe($meet->id)
            ->and($entry->swimEvent->id)->toBe($event->id)
            ->and($entry->club->id)->toBe($club->id);
    })->group('relay-entry');

    it('hat Standard-Status "pending"', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        expect($entry->status)->toBe('pending');
    })->group('relay-entry');

    it('speichert Meldezeit in Millisekunden', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
            'entry_time' => 234500,
            'entry_time_code' => 'A',
            'entry_course' => 'LCM',
        ]);

        expect($entry->entry_time)->toBe(234500)
            ->and($entry->entry_time_code)->toBe('A')
            ->and($entry->entry_course)->toBe('LCM');
    })->group('relay-entry');

    // ── Scopes ────────────────────────────────────────────────────────────────

    it('scope confirmed filtert nur bestätigte Einträge', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club] = relayBase();

        RelayEntry::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'status' => 'pending',
        ]);
        RelayEntry::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'status' => 'confirmed',
        ]);
        RelayEntry::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'status' => 'withdrawn',
        ]);

        expect(RelayEntry::confirmed()->count())->toBe(1);
    })->group('relay-entry');

    it('scope pending filtert nur ausstehende Einträge', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club] = relayBase();

        RelayEntry::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'status' => 'pending',
        ]);
        RelayEntry::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'status' => 'confirmed',
        ]);

        expect(RelayEntry::pending()->count())->toBe(1);
    })->group('relay-entry');

    // ── isComplete ────────────────────────────────────────────────────────────

    it('isComplete gibt false zurück wenn weniger als relay_count Mitglieder', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club, 'nation' => $nation] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        $athlete = makeAthlete($nation, $club);
        RelayEntryMember::create([
            'relay_entry_id' => $entry->id,
            'athlete_id' => $athlete->id,
            'position' => 1,
        ]);

        expect($entry->isComplete())->toBeFalse(); // relay_count = 4, aber nur 1 Mitglied
    })->group('relay-entry');

    it('isComplete gibt true zurück wenn relay_count Mitglieder vorhanden', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club, 'nation' => $nation] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        foreach (range(1, 4) as $pos) {
            $athlete = makeAthlete($nation, $club);
            RelayEntryMember::create([
                'relay_entry_id' => $entry->id,
                'athlete_id' => $athlete->id,
                'position' => $pos,
            ]);
        }

        expect($entry->isComplete())->toBeTrue();
    })->group('relay-entry');

    // ── Cascade Delete ────────────────────────────────────────────────────────

    it('löscht Mitglieder per cascade wenn RelayEntry gelöscht wird', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club, 'nation' => $nation] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        $athlete = makeAthlete($nation, $club);
        RelayEntryMember::create([
            'relay_entry_id' => $entry->id,
            'athlete_id' => $athlete->id,
            'position' => 1,
        ]);

        expect(RelayEntryMember::count())->toBe(1);

        $entry->delete();

        expect(RelayEntryMember::count())->toBe(0);
    })->group('relay-entry');

}); // describe RelayEntry

// ── RelayEntryMember ──────────────────────────────────────────────────────────

describe('RelayEntryMember', function () {

    it('kann angelegt werden', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club, 'nation' => $nation] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        $athlete = makeAthlete($nation, $club);

        $member = RelayEntryMember::create([
            'relay_entry_id' => $entry->id,
            'athlete_id' => $athlete->id,
            'position' => 1,
            'sport_class' => 'S9',
        ]);

        expect($member->id)->toBeInt()
            ->and($member->position)->toBe(1)
            ->and($member->sport_class)->toBe('S9');
    })->group('relay-entry');

    it('hat Relation zu RelayEntry und Athlete', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club, 'nation' => $nation] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        $athlete = makeAthlete($nation, $club);

        $member = RelayEntryMember::create([
            'relay_entry_id' => $entry->id,
            'athlete_id' => $athlete->id,
            'position' => 1,
        ]);

        expect($member->relayEntry->id)->toBe($entry->id)
            ->and($member->athlete->id)->toBe($athlete->id);
    })->group('relay-entry');

    it('verhindert doppelte Athleten in derselben Staffelmeldung', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club, 'nation' => $nation] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        $athlete = makeAthlete($nation, $club);

        RelayEntryMember::create([
            'relay_entry_id' => $entry->id,
            'athlete_id' => $athlete->id,
            'position' => 1,
        ]);

        expect(fn () => RelayEntryMember::create([
            'relay_entry_id' => $entry->id,
            'athlete_id' => $athlete->id,
            'position' => 2,
        ]))->toThrow(QueryException::class);
    })->group('relay-entry');

    // ── resolvedSportClass ────────────────────────────────────────────────────

    it('resolvedSportClass gibt direkt gespeicherte Klasse zurück', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club, 'nation' => $nation] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        $athlete = makeAthlete($nation, $club, 'S7');

        $member = RelayEntryMember::create([
            'relay_entry_id' => $entry->id,
            'athlete_id' => $athlete->id,
            'position' => 1,
            'sport_class' => 'S6', // überschreibt Athleten-Klasse S7
        ]);

        $member->load('athlete.sportClasses');

        expect($member->resolvedSportClass('FREE'))->toBe('S6');
    })->group('relay-entry');

    it('resolvedSportClass liest aus AthleteSportClass wenn kein sport_class gesetzt', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club, 'nation' => $nation] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        $athlete = makeAthlete($nation, $club);

        $member = RelayEntryMember::create([
            'relay_entry_id' => $entry->id,
            'athlete_id' => $athlete->id,
            'position' => 1,
            // sport_class nicht gesetzt → wird aus AthleteSportClass gelesen
        ]);

        $member->load('athlete.sportClasses');

        expect($member->resolvedSportClass('FREE'))->toBe('S9');
    })->group('relay-entry');

    it('resolvedSportClass verwendet SB-Kategorie für BREAST', function () {
        ['meet' => $meet, 'event' => $event, 'club' => $club, 'nation' => $nation] = relayBase();

        $entry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
        ]);

        $athlete = makeAthlete($nation, $club, 'SB9');

        $member = RelayEntryMember::create([
            'relay_entry_id' => $entry->id,
            'athlete_id' => $athlete->id,
            'position' => 1,
        ]);

        $member->load('athlete.sportClasses');

        expect($member->resolvedSportClass('BREAST'))->toBe('SB9');
    })->group('relay-entry');

    it('resolvedSportClass gibt null zurück wenn kein sport_class und kein passender AthleteSportClass-Eintrag',
        function () {
            // Athlet hat nur SB-Klasse, aber wir fragen nach FREE (→ Kategorie S) → kein Treffer → null
            ['meet' => $meet, 'event' => $event, 'club' => $club, 'nation' => $nation] = relayBase();

            $entry = RelayEntry::create([
                'meet_id' => $meet->id,
                'swim_event_id' => $event->id,
                'club_id' => $club->id,
            ]);

            // Athlet hat nur SB9, keine S-Klasse
            $athlete = makeAthlete($nation, $club, 'SB9');

            $member = RelayEntryMember::create([
                'relay_entry_id' => $entry->id,
                'athlete_id' => $athlete->id,
                'position' => 1,
                // sport_class = null → muss aus AthleteSportClass lesen
            ]);

            $member->load('athlete.sportClasses');

            // Kategorie S (FREE) → kein Eintrag → null
            expect($member->resolvedSportClass('FREE'))->toBeNull();
        })->group('relay-entry');

}); // describe RelayEntryMember

<?php

use App\Models\Meet;
use App\Models\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('entries-best-times');

// Nutzt die globalen _p5-Helper (tests/helpers_p5.php).

function clubUser_ebt(int $clubId): User
{
    return User::forceCreate([
        'name' => 'Club EBT',
        'email' => 'club_ebt@example.test',
        'password' => bcrypt('secret'),
        'is_admin' => false,
        'club_id' => $clubId,
    ]);
}

/** Ergebnis mit Zeit in einem Meet mit gegebenem Datum + Kurs. */
function resultAt_ebt($event, $athlete, $club, string $date, string $course, int $time): void
{
    $meet = Meet::create([
        'name' => 'Meet '.$date,
        'nation_id' => makeNation_p5()->id,
        'course' => $course,
        'start_date' => $date,
    ]);
    Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => $time,
        'status' => null,
    ]);
}

describe('club-entries.best-times Endpoint', function () {
    it('liefert Jahres- UND absolute Bestzeit je Kurs', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5(); // start_date 2025-06-15 → Jahresfenster 2024-01-01 .. 2025-06-14
        $meet->update(['is_open' => true]);
        $event = makeEvent_p5($meet, ['sport_classes' => '9', 'gender' => 'M']);
        $athlete = makeAthlete_p5($club);

        // LCM: Jahresbestzeit 6000 (2024), ältere schnellere 5800 (2023, nur absolut)
        resultAt_ebt($event, $athlete, $club, '2024-08-10', 'LCM', 6000);
        resultAt_ebt($event, $athlete, $club, '2023-05-01', 'LCM', 5800);

        $this->actingAs(clubUser_ebt($club->id))
            ->getJson(route('club-entries.best-times', ['meet' => $meet, 'event_id' => $event->id, 'athlete_id' => $athlete->id]))
            ->assertOk()
            ->assertJson([
                'LCM' => [
                    'year' => ['raw' => 6000, 'formatted' => '01:00.00'],
                    'absolute' => ['raw' => 5800, 'formatted' => '00:58.00'],
                ],
                'SCM' => [
                    'year' => ['raw' => null, 'formatted' => 'NT'],
                    'absolute' => ['raw' => null, 'formatted' => 'NT'],
                ],
            ]);
    });
});

describe('club-entries/create Bestzeiten-Panel', function () {
    it('rendert das Panel mit Jahres- und absoluter Bestzeit und Klick-Übernahme', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);

        $html = $this->actingAs(clubUser_ebt($club->id))
            ->get(route('club-entries.create', ['meet' => $meet]))
            ->assertOk()
            ->getContent();

        expect($html)
            ->toContain('Jahresbestzeit (Vorjahr bis Wettkampfbeginn)')
            ->toContain('Absolute Bestzeit')
            ->toContain('applyTime(')
            // kein alter "Bestzeit übernehmen"-Button mehr
            ->not->toContain('applyBestTime');
    });
});

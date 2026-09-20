<?php

use App\Models\Entry;
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

/**
 * Ergebnis mit Zeit in einem ANDEREN Meet (eigenes Datum + Kurs). Das Ergebnis hängt bewusst
 * an einem eigenen swim_event derselben Disziplin (Distanz/Stil/relay_count wie $event) — so
 * wie in echt: historische Zeiten liegen an vergangenen Meets, nicht am aktuellen Meet-Event.
 */
function resultAt_ebt($event, $athlete, $club, string $date, string $course, int $time): void
{
    $meet = Meet::create([
        'name' => 'Meet '.$date,
        'nation_id' => makeNation_p5()->id,
        'course' => $course,
        'start_date' => $date,
    ]);
    $histEvent = makeEvent_p5($meet, [
        'distance' => $event->distance,
        'stroke_type_id' => $event->stroke_type_id,
        'relay_count' => $event->relay_count,
        'gender' => $event->gender,
    ]);
    Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $histEvent->id,
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
                    'year' => ['raw' => 6000, 'formatted' => '01:00.00', 'date' => '10.08.2024'],
                    'absolute' => ['raw' => 5800, 'formatted' => '00:58.00', 'date' => '01.05.2023'],
                ],
                'SCM' => [
                    'year' => ['raw' => null, 'formatted' => 'NT', 'date' => null],
                    'absolute' => ['raw' => null, 'formatted' => 'NT', 'date' => null],
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
            ->toContain('Jahresbestzeit')
            ->toContain('(Vorjahr bis Wettkampfbeginn)')
            ->toContain('Absolute Bestzeit')
            ->toContain('applyTime(')
            // kein alter "Bestzeit übernehmen"-Button mehr
            ->not->toContain('applyBestTime');
    });
});

// ── Admin-seitige Meldungserfassung (entries/form, nicht club-scoped) ────────────

function admin_ebt(): User
{
    return User::forceCreate([
        'name' => 'Admin EBT',
        'email' => 'admin_ebt@example.test',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);
}

describe('meets.entries.best-times Endpoint (Admin)', function () {
    it('liefert Jahres- + absolute Bestzeit für einen beliebigen Athleten (nicht club-scoped)', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);
        $event = makeEvent_p5($meet, ['sport_classes' => '9', 'gender' => 'M']);
        $athlete = makeAthlete_p5($club); // Admin ist in keinem Verein — darf trotzdem abfragen

        resultAt_ebt($event, $athlete, $club, '2024-08-10', 'LCM', 6000);
        resultAt_ebt($event, $athlete, $club, '2023-05-01', 'LCM', 5800);

        $this->actingAs(admin_ebt())
            ->getJson(route('meets.entries.best-times', ['meet' => $meet, 'event_id' => $event->id, 'athlete_id' => $athlete->id]))
            ->assertOk()
            ->assertJson([
                'LCM' => [
                    'year' => ['raw' => 6000, 'formatted' => '01:00.00'],
                    'absolute' => ['raw' => 5800, 'formatted' => '00:58.00'],
                ],
            ]);
    });
});

describe('entries/form Bestzeiten-Panel (Admin)', function () {
    it('verdrahtet das Panel und den best-times-Endpoint', function () {
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);

        $html = $this->actingAs(admin_ebt())
            ->get(route('meets.entries.create', ['meet' => $meet]))
            ->assertOk()
            ->getContent();

        expect($html)
            ->toContain('entryBestTimes(')
            // @json escaped die Slashes → "…\/entries\/best-times"
            ->toContain('best-times')
            ->toContain('Absolute Bestzeit')
            ->toContain('applyTime(');
    });

    it('zeigt das Panel auch beim Bearbeiten einer Meldung (fixer Athlet + Disziplin)', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);
        $event = makeEvent_p5($meet, ['sport_classes' => '9', 'gender' => 'M']);
        $athlete = makeAthlete_p5($club);
        $entry = Entry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id,
            'club_id' => $club->id,
        ]);

        $html = $this->actingAs(admin_ebt())
            ->get(route('entries.edit', $entry))
            ->assertOk()
            ->getContent();

        expect($html)
            ->toContain('entryBestTimes(')
            ->toContain('best-times')
            ->toContain('Jahresbestzeit')
            ->toContain('Absolute Bestzeit')
            ->toContain('applyTime(');
    });
});

describe('club-entries/edit Bestzeiten-Panel (server-seitig)', function () {
    it('rendert Jahres- + absolute Bestzeit mit Zeit + Datum und klickbarer Übernahme', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);
        $event = makeEvent_p5($meet, ['sport_classes' => '9', 'gender' => 'M']);
        $athlete = makeAthlete_p5($club);
        resultAt_ebt($event, $athlete, $club, '2024-08-10', 'LCM', 6000);

        $entry = Entry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id,
            'club_id' => $club->id,
        ]);

        $html = $this->actingAs(clubUser_ebt($club->id))
            ->get(route('club-entries.edit', [$meet, $entry]))
            ->assertOk()
            ->getContent();

        expect($html)
            ->toContain('Jahresbestzeit')
            ->toContain('Absolute Bestzeit')
            ->toContain('(alle Wettkämpfe)')
            ->toContain('01:00.00')   // Zeit
            ->toContain('10.08.2024') // Datum
            // klickbare Übernahme setzt entryTime + entryCourse
            ->toContain("entryTime = '01:00.00'; entryCourse = 'LCM'");
    });
});

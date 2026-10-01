<?php

use App\Models\Entry;
use App\Models\Meet;
use App\Models\MeetSession;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\LenexExportService;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use App\Services\MeetEntryListService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meet-session-dates');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeAthlete_p5(), makeEvent_p5(), makeStrokeType_p5().

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_msd(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Dreitägige Veranstaltung 17.–19.10.2026 mit Disziplinen in Abschnitt 1 und 2. */
function multiDayMeet_msd(): Meet
{
    $meet = makeMeet_p5();
    $meet->update(['start_date' => '2026-10-17', 'end_date' => '2026-10-19']);

    return $meet;
}

function event_msd(Meet $meet, int $session, int $number): SwimEvent
{
    return makeEvent_p5($meet, ['session_number' => $session, 'event_number' => $number]);
}

function entry_msd(Meet $meet, SwimEvent $event): Entry
{
    $club = makeClub_p5();

    return Entry::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => makeAthlete_p5($club)->id,
        'club_id' => $club->id,
    ]);
}

/** @return array<int, string> Abschnitts-Überschriften der Meldeliste nach Bewerben */
function sessionLabels_msd(Meet $meet): array
{
    return app(MeetEntryListService::class)->byEvent($meet)->pluck('label')->all();
}

/**
 * LENEX-Export für den Test. build() deklariert eine DOMException; sie wird hier einmalig abgefangen und als
 * RuntimeException weitergereicht (Muster wie buildLenex_p7() in LenexRelayExportTest).
 */
function lenex_msd(Meet $meet): SimpleXMLElement
{
    try {
        return simplexml_load_string((new LenexExportService)->build($meet, 'entries'));
    } catch (DOMException $e) {
        throw new RuntimeException('LENEX-Export fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

/** LENEX-Import einer Strukturdatei in eine bestehende Veranstaltung (Exception → RuntimeException). */
function importLenex_msd(string $xml, Meet $meet): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_msd').'.lef';
    file_put_contents($path, $xml);

    try {
        (new LenexParserService)->import($path, new LenexResolverService, $meet->id);
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

// ── Pflege-Formular ───────────────────────────────────────────────────────────

it('zeigt im Formular alle Abschnitte der Disziplinen mit gespeicherten Werten', function () {
    $meet = multiDayMeet_msd();
    event_msd($meet, 1, 1);
    event_msd($meet, 2, 2);
    MeetSession::create(['meet_id' => $meet->id, 'number' => 2, 'date' => '2026-10-18', 'daytime' => '09:30']);

    $this->actingAs(admin_msd())
        ->get(route('meets.sessions.edit', $meet))
        ->assertOk()
        ->assertSee('Abschnitt 1')
        ->assertSee('Abschnitt 2')
        ->assertSee('value="2026-10-18"', false)
        ->assertSee('value="09:30"', false);
});

it('speichert Datum und Startzeit je Abschnitt und entfernt geleerte Abschnitte', function () {
    $meet = multiDayMeet_msd();
    event_msd($meet, 1, 1);
    event_msd($meet, 2, 2);
    MeetSession::create(['meet_id' => $meet->id, 'number' => 2, 'date' => '2026-10-18']);

    $this->actingAs(admin_msd())
        ->put(route('meets.sessions.update', $meet), ['sessions' => [
            1 => ['date' => '2026-10-17', 'daytime' => '10:00'],
            2 => ['date' => '', 'daytime' => ''],
        ]])
        ->assertRedirect(route('meets.show', $meet));

    $first = MeetSession::where('meet_id', $meet->id)->where('number', 1)->first();

    expect($first->date->toDateString())->toBe('2026-10-17')
        ->and($first->daytime_short)->toBe('10:00')
        ->and(MeetSession::where('meet_id', $meet->id)->where('number', 2)->exists())->toBeFalse();
});

it('lehnt ein Datum außerhalb der Veranstaltung und eine ungültige Startzeit ab', function () {
    $meet = multiDayMeet_msd();
    event_msd($meet, 1, 1);

    $this->actingAs(admin_msd())
        ->put(route('meets.sessions.update', $meet), ['sessions' => [
            1 => ['date' => '2026-10-20', 'daytime' => '25:00'],
        ]])
        ->assertSessionHasErrors([
            'sessions.1.date' => 'Das Datum muss im Zeitraum der Veranstaltung liegen.',
            'sessions.1.daytime' => 'Die Startzeit muss im Format HH:MM angegeben werden.',
        ]);

    expect(MeetSession::count())->toBe(0);
});

it('ignoriert Abschnittsnummern ohne Disziplinen', function () {
    $meet = multiDayMeet_msd();
    event_msd($meet, 1, 1);

    $this->actingAs(admin_msd())
        ->put(route('meets.sessions.update', $meet), ['sessions' => [
            7 => ['date' => '2026-10-18', 'daytime' => ''],
        ]])
        ->assertRedirect(route('meets.show', $meet));

    expect(MeetSession::count())->toBe(0);
});

it('zeigt auf der Veranstaltungsseite das Abschnittsdatum und den Bearbeiten-Button', function () {
    $meet = multiDayMeet_msd();
    event_msd($meet, 1, 1);
    MeetSession::create(['meet_id' => $meet->id, 'number' => 1, 'date' => '2026-10-17', 'daytime' => '09:00']);

    $this->actingAs(admin_msd())
        ->get(route('meets.show', $meet))
        ->assertOk()
        ->assertSee('Samstag, 17. Oktober 2026, 09:00 Uhr')
        ->assertSee(route('meets.sessions.edit', $meet));
});

// ── Meldeliste nach Bewerben ──────────────────────────────────────────────────

it('beschriftet Abschnitte mit gepflegtem Datum, sonst ohne Datum bei mehrtägigen Veranstaltungen', function () {
    $meet = multiDayMeet_msd();
    entry_msd($meet, event_msd($meet, 1, 1));
    entry_msd($meet, event_msd($meet, 2, 2));
    MeetSession::create(['meet_id' => $meet->id, 'number' => 2, 'date' => '2026-10-18', 'daytime' => '09:00']);

    // Startzeit wird bewusst nicht angezeigt.
    expect(sessionLabels_msd($meet))->toBe([
        'Abschnitt 1',
        'Abschnitt 2 - Sonntag, 18. Oktober 2026',
    ]);
});

it('nimmt bei eintägigen Veranstaltungen ohne gepflegtes Datum das Veranstaltungsdatum', function () {
    $meet = makeMeet_p5();   // eintägig, 15.06.2025
    entry_msd($meet, event_msd($meet, 1, 1));

    expect(sessionLabels_msd($meet))->toBe(['Abschnitt 1 - Sonntag, 15. Juni 2025']);
});

// ── LENEX ─────────────────────────────────────────────────────────────────────

it('exportiert Datum und Startzeit je Abschnitt, ohne Eintrag den Veranstaltungsbeginn', function () {
    $meet = multiDayMeet_msd();
    event_msd($meet, 1, 1);
    event_msd($meet, 2, 2);
    MeetSession::create(['meet_id' => $meet->id, 'number' => 2, 'date' => '2026-10-18', 'daytime' => '09:30']);

    $sessions = [];
    foreach (lenex_msd($meet)->MEETS->MEET->SESSIONS->SESSION as $session) {
        $sessions[(string) $session['number']] = [(string) $session['date'], (string) $session['daytime']];
    }

    expect($sessions)->toBe([
        '1' => ['2026-10-17', ''],
        '2' => ['2026-10-18', '09:30'],
    ]);
});

it('übernimmt beim LENEX-Import Datum und Startzeit je Abschnitt', function () {
    $meet = multiDayMeet_msd();
    makeStrokeType_p5();

    importLenex_msd(<<<'XML'
        <LENEX version="3.0">
          <MEETS>
            <MEET name="Testmeet" course="LCM" startdate="2026-10-17" stopdate="2026-10-19">
              <SESSIONS>
                <SESSION number="1" date="2026-10-17" daytime="09:00">
                  <EVENTS>
                    <EVENT eventid="1" number="1" gender="M" round="TIM">
                      <SWIMSTYLE distance="100" relaycount="1" stroke="FREE"/>
                    </EVENT>
                  </EVENTS>
                </SESSION>
                <SESSION number="2" date="2026-10-18" daytime="15:30">
                  <EVENTS>
                    <EVENT eventid="2" number="2" gender="F" round="TIM">
                      <SWIMSTYLE distance="50" relaycount="1" stroke="FREE"/>
                    </EVENT>
                  </EVENTS>
                </SESSION>
              </SESSIONS>
            </MEET>
          </MEETS>
        </LENEX>
        XML, $meet);

    $sessions = MeetSession::where('meet_id', $meet->id)->orderBy('number')->get();

    expect($sessions)->toHaveCount(2)
        ->and($sessions[0]->date->toDateString())->toBe('2026-10-17')
        ->and($sessions[0]->daytime_short)->toBe('09:00')
        ->and($sessions[1]->date->toDateString())->toBe('2026-10-18')
        ->and($sessions[1]->daytime_short)->toBe('15:30')
        ->and($meet->swimEvents()->pluck('session_number')->sort()->values()->all())->toBe([1, 2]);
});

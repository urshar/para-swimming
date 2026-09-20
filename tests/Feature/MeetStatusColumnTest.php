<?php

use App\Models\Entry;
use App\Models\RelayEntry;
use App\Models\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meets-status-column');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeAthlete_p5(), makeEvent_p5().

function admin_msc(): User
{
    return User::forceCreate([
        'name' => 'Admin MSC',
        'email' => 'admin_msc@example.test',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);
}

/** Liefert das Meet aus der echten Controller-Query (inkl. der withCount/withExists-Aggregate). */
function fetchMeet_msc(object $test, int $meetId): object
{
    $response = $test->actingAs(admin_msc())->get(route('meets.index'));
    $response->assertOk();

    return $response->viewData('meets')->firstWhere('id', $meetId);
}

describe('meets/index I/E/R-Status', function () {
    it('setzt I nur, wenn ALLE Disziplinen Wertungsgruppen haben, und E/R nach Meldungen/Ergebnissen', function () {
        $club = makeClub_p5();
        $athlete = makeAthlete_p5($club);

        // Voll: eine konfigurierte Disziplin + Einzelmeldung + Ergebnis.
        $voll = makeMeet_p5();
        $eVoll = makeEvent_p5($voll, ['sport_classes' => '9']);
        Entry::create(['meet_id' => $voll->id, 'swim_event_id' => $eVoll->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id]);
        Result::create(['meet_id' => $voll->id, 'swim_event_id' => $eVoll->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id, 'sport_class' => 'S9', 'swim_time' => 10000]);

        $m = fetchMeet_msc($this, $voll->id);
        expect($m->swim_events_count)->toBe(1)
            ->and((int) $m->unconfigured_events_count)->toBe(0)
            ->and((bool) $m->entries_exists)->toBeTrue()
            ->and((bool) $m->results_exists)->toBeTrue()
            // abgeleitetes I/E/R
            ->and($m->swim_events_count > 0 && ! $m->unconfigured_events_count)->toBeTrue();
    });

    it('lässt I aus, wenn eine Disziplin keine Wertungsgruppen hat', function () {
        $teil = makeMeet_p5();
        makeEvent_p5($teil, ['sport_classes' => '9']);
        makeEvent_p5($teil, ['sport_classes' => null]);

        $m = fetchMeet_msc($this, $teil->id);
        expect($m->swim_events_count)->toBe(2)
            ->and((int) $m->unconfigured_events_count)->toBe(1)
            ->and($m->swim_events_count > 0 && ! $m->unconfigured_events_count)->toBeFalse()
            ->and((bool) $m->entries_exists)->toBeFalse()
            ->and((bool) $m->relay_entries_exists)->toBeFalse()
            ->and((bool) $m->results_exists)->toBeFalse();
    });

    it('wertet einen leeren sport_classes-String wie fehlende Wertungsgruppen', function () {
        $leerString = makeMeet_p5();
        makeEvent_p5($leerString, ['sport_classes' => '']);

        $m = fetchMeet_msc($this, $leerString->id);
        expect((int) $m->unconfigured_events_count)->toBe(1)
            ->and($m->swim_events_count > 0 && ! $m->unconfigured_events_count)->toBeFalse();
    });

    it('lässt I aus, wenn gar keine Disziplinen angelegt sind', function () {
        $leer = makeMeet_p5();

        $m = fetchMeet_msc($this, $leer->id);
        expect($m->swim_events_count)->toBe(0)
            ->and($m->swim_events_count > 0 && ! $m->unconfigured_events_count)->toBeFalse();
    });

    it('setzt E auch bei reiner Staffelmeldung (ohne Einzelmeldung)', function () {
        $club = makeClub_p5();
        $nurStaffel = makeMeet_p5();
        $relayEvent = makeEvent_p5($nurStaffel, ['relay_count' => 4, 'sport_classes' => '9']);
        RelayEntry::create(['meet_id' => $nurStaffel->id, 'swim_event_id' => $relayEvent->id, 'club_id' => $club->id, 'relay_class' => 'S20', 'status' => 'pending']);

        $m = fetchMeet_msc($this, $nurStaffel->id);
        expect((bool) $m->entries_exists)->toBeFalse()
            ->and((bool) $m->relay_entries_exists)->toBeTrue()
            ->and($m->entries_exists || $m->relay_entries_exists)->toBeTrue()
            ->and((bool) $m->results_exists)->toBeFalse();
    });

    it('rendert die I/E/R-Spalte und nicht mehr den LENEX-Status', function () {
        $meet = makeMeet_p5(); // lenex_status bleibt leer/Default
        makeEvent_p5($meet, ['sport_classes' => '9']);

        $this->actingAs(admin_msc())
            ->get(route('meets.index'))
            ->assertOk()
            ->assertSee('title="I —', false)
            ->assertSee('title="E —', false)
            ->assertSee('title="R —', false);
    });
});

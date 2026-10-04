<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\PointSystem;
use App\Models\Result;
use App\Models\ResultSplit;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meet-results-overview');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_mro(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Veranstaltung mit zwei Einzelbewerben, Verein und Athlet. */
function setup_mro(): array
{
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $event = makeEvent_p5($meet, ['event_number' => 1]);
    $otherEvent = makeEvent_p5($meet, ['event_number' => 2, 'distance' => 200]);

    return [$meet, $club, $athlete, $event, $otherEvent];
}

function result_mro(Meet $meet, SwimEvent $event, Athlete $athlete, ?int $place, ?int $time, ?string $status): Result
{
    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => $time,
        'place' => $place,
        'status' => $status,
        'sport_class' => 'S9',
    ]);
}

/** Übersicht-URL so, wie die Middleware sie per fullUrl() merkt (Query alphabetisch sortiert). */
function overviewUrl_mro(Meet $meet, array $query): string
{
    ksort($query);

    return route('meets.results-overview', array_merge(['meet' => $meet], $query));
}

/** Gültiger Formular-Request für ResultController::store(). */
function payload_mro(SwimEvent $event, Athlete $athlete, Club $club): array
{
    return [
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => '01:05.30',
        'sport_class' => 'S9',
    ];
}

// ── Liste ─────────────────────────────────────────────────────────────────────

it('zeigt die Ergebnisse nach Disziplin gruppiert, platziert vor unplatziert und ohne Zeit am Ende', function () {
    [$meet, $club, , $event] = setup_mro();
    $second = makeAthlete_p5($club);
    $second->update(['last_name' => 'Zweiter']);
    $first = makeAthlete_p5($club);
    $first->update(['last_name' => 'Erster']);
    $dns = makeAthlete_p5($club);
    $dns->update(['last_name' => 'Nichtangetreten']);
    $exhibition = makeAthlete_p5($club);
    $exhibition->update(['last_name' => 'Außerkonkurrenz']);

    result_mro($meet, $event, $dns, null, null, 'DNS');
    result_mro($meet, $event, $exhibition, null, 70000, 'EXH');
    result_mro($meet, $event, $second, 2, 66000, null);
    result_mro($meet, $event, $first, 1, 65000, null);

    $this->actingAs(admin_mro())
        ->get(route('meets.results-overview', $meet))
        ->assertOk()
        ->assertSee('4 Ergebnisse')
        ->assertSeeInOrder(['Erster', 'Zweiter', 'Außerkonkurrenz', 'Nichtangetreten'])
        ->assertSee('title="Außer Konkurrenz"', false)
        ->assertSee('manuell');
});

it('zeigt ÖBSV- und WPS-Punkte in eigenen Spalten und markiert geschätzte WPS-Punkte', function () {
    [$meet, , $athlete, $event] = setup_mro();
    $result = result_mro($meet, $event, $athlete, 1, 65000, null);
    $result->forceFill(['points' => 512, 'wps_points' => 97, 'wps_calculation_type' => Result::WPS_TYPE_ESTIMATED])->save();

    $this->actingAs(admin_mro())
        ->get(route('meets.results-overview', $meet))
        ->assertSeeInOrder(['Punkte', 'WPS'])
        ->assertSee('512')
        ->assertSee('title="Geschätzt, nicht offiziell (abgeleitete Kurzbahn-Parameter)">97*</span>', false)
        ->assertDontSee('· WPS');
});

it('zeigt die WPS-Spalte erst, wenn WPS für die Veranstaltung aktiviert ist', function () {
    [$meet, , $athlete, $event] = setup_mro();
    result_mro($meet, $event, $athlete, 1, 65000, null);
    $admin = admin_mro();

    $this->actingAs($admin)->get(route('meets.results-overview', $meet))
        ->assertDontSee('World-Para-Swimming-Punkte');

    $wps = PointSystem::firstOrCreate(['code' => PointSystem::CODE_WPS], ['name' => 'WPS', 'active' => true]);
    $meet->pointSystems()->attach($wps->id);

    $this->get(route('meets.results-overview', $meet))
        ->assertSee('World-Para-Swimming-Punkte');
});

it('filtert nach Disziplin, Verein und Athlet', function () {
    [$meet, $club, $athlete, $event, $otherEvent] = setup_mro();
    $athlete->update(['last_name' => 'Gesuchter']);
    $otherClub = makeClub_p5();
    $other = makeAthlete_p5($otherClub);
    $other->update(['last_name' => 'Andersverein']);
    result_mro($meet, $event, $athlete, 1, 65000, null);
    result_mro($meet, $otherEvent, $other, 1, 150000, null);

    $admin = admin_mro();

    $this->actingAs($admin)->get(route('meets.results-overview', ['meet' => $meet, 'event_id' => $otherEvent->id]))
        ->assertSee('Andersverein')->assertDontSee('Gesuchter');
    $this->get(route('meets.results-overview', ['meet' => $meet, 'club_id' => $club->id]))
        ->assertSee('Gesuchter')->assertDontSee('Andersverein');
    $this->get(route('meets.results-overview', ['meet' => $meet, 'search' => 'Gesuch']))
        ->assertSee('Gesuchter')->assertDontSee('Andersverein')
        ->assertSee('Zurücksetzen');
});

it('ignoriert einen Disziplin-Filter einer anderen Veranstaltung', function () {
    [$meet, , $athlete, $event] = setup_mro();
    $foreignEvent = makeEvent_p5(makeMeet_p5());
    result_mro($meet, $event, $athlete, 1, 65000, null);

    $this->actingAs(admin_mro())
        ->get(route('meets.results-overview', ['meet' => $meet, 'event_id' => $foreignEvent->id]))
        ->assertOk()
        ->assertSee($athlete->last_name);
});

it('ist nur für Admins erreichbar', function () {
    [$meet, $club, , $event] = setup_mro();
    $clubUser = User::factory()->create(['is_admin' => false, 'club_id' => $club->id]);

    $this->actingAs($clubUser)->get(route('meets.results-overview', $meet))->assertForbidden();
    $this->delete(route('meets.results-overview.destroy-event', ['meet' => $meet, 'swimEvent' => $event]))
        ->assertForbidden();
});

it('verlinkt die Sammelansicht von der Veranstaltungsseite', function () {
    [$meet] = setup_mro();

    $this->actingAs(admin_mro())
        ->get(route('meets.show', $meet))
        ->assertSee('href="'.route('meets.results-overview', $meet).'"', false);
});

it('kehrt nach "Rekorde prüfen" zur aufrufenden Seite zurück und zeigt dort das Prüfergebnis', function () {
    [$meet, , $athlete, $event] = setup_mro();
    result_mro($meet, $event, $athlete, 1, 65000, null);
    $overview = route('meets.results-overview', $meet);

    $this->actingAs(admin_mro())
        ->from($overview)
        ->post(route('records.check', $meet))
        ->assertRedirect($overview);

    $this->get($overview)->assertSee('Ergebnisse geprüft');
});

// ── Löschen ───────────────────────────────────────────────────────────────────

it('löscht ein einzelnes Ergebnis und kehrt in die gefilterte Sammelansicht zurück', function () {
    [$meet, , $athlete, $event] = setup_mro();
    $result = result_mro($meet, $event, $athlete, 1, 65000, null);
    $listUrl = overviewUrl_mro($meet, ['event_id' => $event->id]);

    $this->actingAs(admin_mro())->get($listUrl)->assertOk();
    $this->delete(route('results.destroy', $result))->assertRedirect($listUrl);

    expect(Result::find($result->id))->toBeNull();
});

it('löscht alle Ergebnisse einer Disziplin samt Splits, andere Disziplinen bleiben', function () {
    [$meet, $club, $athlete, $event, $otherEvent] = setup_mro();
    $result = result_mro($meet, $event, $athlete, 1, 65000, null);
    ResultSplit::create(['result_id' => $result->id, 'distance' => 50, 'split_time' => 3100]);
    result_mro($meet, $event, makeAthlete_p5($club), 2, 66000, null);
    $kept = result_mro($meet, $otherEvent, $athlete, 1, 150000, null);

    $this->actingAs(admin_mro())->get(route('meets.results-overview', $meet))->assertOk();
    $this->delete(route('meets.results-overview.destroy-event', ['meet' => $meet, 'swimEvent' => $event]))
        ->assertRedirect(route('meets.results-overview', $meet))
        ->assertSessionHas('success', '2 Ergebnisse von "'.$event->display_name.'" gelöscht.');

    expect(Result::where('swim_event_id', $event->id)->count())->toBe(0)
        ->and(ResultSplit::count())->toBe(0)
        ->and(Result::find($kept->id))->not->toBeNull();
});

it('nennt in der Rückfrage zum Löschen aller Ergebnisse auch die per Filter ausgeblendeten', function () {
    [$meet, $club, $athlete, $event] = setup_mro();
    $athlete->update(['last_name' => 'Sichtbar']);
    result_mro($meet, $event, $athlete, 1, 65000, null);
    result_mro($meet, $event, makeAthlete_p5(makeClub_p5()), 2, 66000, null);

    $this->actingAs(admin_mro())
        ->get(route('meets.results-overview', ['meet' => $meet, 'club_id' => $club->id]))
        ->assertSee('Alle 2 Ergebnisse von &quot;'.e($event->display_name).'&quot; löschen (auch die durch den Filter ausgeblendeten)?', false);
});

it('löscht keine Ergebnisse über eine Disziplin einer anderen Veranstaltung', function () {
    [$meet, , $athlete, $event] = setup_mro();
    result_mro($meet, $event, $athlete, 1, 65000, null);

    $this->actingAs(admin_mro())
        ->delete(route('meets.results-overview.destroy-event', ['meet' => makeMeet_p5(), 'swimEvent' => $event]))
        ->assertNotFound();

    expect(Result::count())->toBe(1);
});

// ── Erfassen ──────────────────────────────────────────────────────────────────

it('kehrt nach dem Erfassen in die Sammelansicht zurück', function () {
    [$meet, $club, $athlete, $event] = setup_mro();
    $listUrl = overviewUrl_mro($meet, ['club_id' => $club->id]);

    $this->actingAs(admin_mro())->get($listUrl)->assertOk();
    $this->post(route('meets.results.store', $meet), payload_mro($event, $athlete, $club))
        ->assertRedirect($listUrl);

    expect(Result::where('swim_event_id', $event->id)->count())->toBe(1);
});

it('öffnet nach "Speichern und nächstes" das Formular mit derselben Disziplin', function () {
    [$meet, $club, $athlete, , $otherEvent] = setup_mro();
    $admin = admin_mro();

    $this->actingAs($admin)
        ->post(route('meets.results.store', $meet), payload_mro($otherEvent, $athlete, $club) + ['save_next' => '1'])
        ->assertRedirect(route('meets.results.create', ['meet' => $meet, 'swim_event_id' => $otherEvent->id]));

    $this->get(route('meets.results.create', ['meet' => $meet, 'swim_event_id' => $otherEvent->id]))
        ->assertOk()
        ->assertSee('eventId: "'.$otherEvent->id.'"', false)
        ->assertSee('Speichern und nächstes');

    expect(Result::where('swim_event_id', $otherEvent->id)->count())->toBe(1);
});

it('kehrt nach dem Bearbeiten in die Sammelansicht zurück, wenn man von dort kam', function () {
    [$meet, $club, $athlete, $event] = setup_mro();
    $result = result_mro($meet, $event, $athlete, 1, 65000, null);
    $listUrl = overviewUrl_mro($meet, ['search' => 'Muster']);

    $this->actingAs(admin_mro())->get($listUrl)->assertOk();
    $this->put(route('results.update', $result), payload_mro($event, $athlete, $club))
        ->assertRedirect($listUrl);
});

<?php

use App\Models\BaseTimeSportClass;
use App\Models\Classifier;
use App\Models\Entry;
use App\Models\Nation;
use App\Models\RelayEntry;
use App\Models\Result;
use App\Models\SwimRecord;
use App\Models\User;
use App\Support\ListUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('context-back-buttons');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeAthlete_p5(), makeEvent_p5(), makeStrokeType_p5(), makeNation_p5().

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_cbb(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/**
 * Listen-URL so, wie die Middleware sie über $request->fullUrl() speichert: Symfony normalisiert dabei
 * die Query-Parameter (alphabetisch sortiert) — die erwartete URL muss dieselbe Reihenfolge haben.
 */
function listUrl_cbb(string $route, array $params): string
{
    ksort($params);

    return route($route, $params);
}

/**
 * Sportklassen-Auswahl der Rekordliste (RecordController::buildSportClassOptions() liest sie aus
 * base_time_sport_classes): S1 wird Default ("S01"), S6 ist die davon abweichende, gefilterte Ansicht.
 */
function seedSportClasses_cbb(): void
{
    BaseTimeSportClass::create(['code' => 'S1', 'sort_order' => 1]);
    BaseTimeSportClass::create(['code' => 'S6', 'sort_order' => 6]);
}

/** Rekordliste mit den echten Filterparametern, wie das Filterformular sie sendet (S6, Langbahn). */
function recordListUrl_cbb(): string
{
    return listUrl_cbb('records.index', ['type' => 'AUT', 'sport_class' => 'S6,SB6,SM6', 'course' => 'LCM']);
}

function makeRecord_cbb(): SwimRecord
{
    $club = makeClub_p5();

    return SwimRecord::create([
        'stroke_type_id' => makeStrokeType_p5()->id,
        'athlete_id' => makeAthlete_p5($club)->id,
        'club_id' => $club->id,
        'record_type' => 'AUT',
        'sport_class' => 'S6',
        'gender' => 'M',
        'course' => 'LCM',
        'distance' => 50,
        'relay_count' => 1,
        'swim_time' => 6000,
        'record_status' => 'APPROVED',
        'is_current' => true,
        'set_date' => '2025-01-01',
    ]);
}

function makeResult_cbb(): Result
{
    $meet = makeMeet_p5();
    $club = makeClub_p5();

    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => makeEvent_p5($meet)->id,
        'athlete_id' => makeAthlete_p5($club)->id,
        'club_id' => $club->id,
        'swim_time' => 10000,
        'sport_class' => 'S9',
    ]);
}

function makeEntry_cbb(): Entry
{
    $meet = makeMeet_p5();
    $club = makeClub_p5();

    return Entry::create([
        'meet_id' => $meet->id,
        'swim_event_id' => makeEvent_p5($meet)->id,
        'athlete_id' => makeAthlete_p5($club)->id,
        'club_id' => $club->id,
    ]);
}

// ── ListUrl / Middleware ──────────────────────────────────────────────────────

it('fällt ohne gemerkte Liste auf die ungefilterte Übersicht zurück', function () {
    expect(ListUrl::to('clubs'))->toBe(route('clubs.index'))
        ->and(ListUrl::to('records'))->toBe(route('records.index'));
});

it('übernimmt keine externe URL aus der Session', function () {
    session(['list_url.clubs' => 'https://evil.example/phish']);

    expect(ListUrl::to('clubs'))->toBe(route('clubs.index'));
});

it('merkt sich die Liste nicht bei JSON-Abfragen', function () {
    $this->actingAs(admin_cbb())
        ->getJson(route('clubs.index', ['search' => 'Json']))
        ->assertOk();

    expect(session('list_url.clubs'))->toBeNull();
});

// ── Rekorde (Auslöser: Zurück landete immer bei S01) ──────────────────────────

it('führt von der Rekord-Detailseite zurück zur zuletzt gefilterten Rekordliste', function () {
    seedSportClasses_cbb();
    $record = makeRecord_cbb();
    $admin = admin_cbb();
    $listUrl = recordListUrl_cbb();

    // Der Filter ist echt: Die S6-Liste zeigt den Rekord, die ungefilterte Liste (Default S01) nicht —
    // genau der alte Fehler, bei dem Zurück immer bei S01 landete.
    $this->actingAs($admin)->get($listUrl)->assertOk()
        ->assertSee(route('records.show', $record));
    $this->actingAs($admin)->get(route('records.index', ['type' => 'AUT', 'course' => 'LCM']))->assertOk()
        ->assertDontSee(route('records.show', $record));

    // Die ungefilterte Liste hat die gemerkte URL überschrieben — erneut die S6-Liste öffnen.
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertSee('href="'.e($listUrl).'"', false);
});

it('führt vom Rekord-Bearbeiten zur Detailseite und vom Anlegen zur gemerkten Liste', function () {
    seedSportClasses_cbb();
    $record = makeRecord_cbb();
    $admin = admin_cbb();
    $listUrl = recordListUrl_cbb();
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)
        ->get(route('records.edit', $record))
        ->assertOk()
        ->assertSee('href="'.route('records.show', $record).'"', false);

    $this->actingAs($admin)
        ->get(route('records.create'))
        ->assertOk()
        ->assertSee($listUrl);
});

it('leitet nach dem Löschen eines Rekords auf die gefilterte Liste zurück', function () {
    seedSportClasses_cbb();
    $record = makeRecord_cbb();
    $admin = admin_cbb();
    $listUrl = recordListUrl_cbb();
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)
        ->delete(route('records.destroy', $record))
        ->assertRedirect($listUrl);
});

// ── Vereine ───────────────────────────────────────────────────────────────────

it('führt bei Vereinen zurück zur gefilterten Liste bzw. zur Detailseite', function () {
    $club = makeClub_p5();
    $admin = admin_cbb();
    $listUrl = listUrl_cbb('clubs.index', ['search' => 'Test', 'page' => 2]);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)->get(route('clubs.show', $club))->assertOk()->assertSee($listUrl);
    $this->actingAs($admin)->get(route('clubs.create'))->assertOk()->assertSee($listUrl);
    $this->actingAs($admin)->get(route('clubs.edit', $club))->assertOk()->assertSee('href="'.route('clubs.show', $club).'"', false);
});

it('leitet nach dem Löschen eines Vereins auf die gefilterte Liste zurück', function () {
    $club = makeClub_p5();
    $admin = admin_cbb();
    $listUrl = listUrl_cbb('clubs.index', ['search' => 'Test']);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)->delete(route('clubs.destroy', $club))->assertRedirect($listUrl);
});

it('führt bei Direktaufruf der Vereinsseite auf die ungefilterte Liste', function () {
    $this->actingAs(admin_cbb())
        ->get(route('clubs.show', makeClub_p5()))
        ->assertOk()
        ->assertSee('href="'.route('clubs.index').'"', false);
});

// ── Klassifizierer ────────────────────────────────────────────────────────────

it('führt bei Klassifizierern zurück zur gefilterten Liste und leitet nach dem Löschen dorthin', function () {
    $classifier = Classifier::create([
        'first_name' => 'Kla', 'last_name' => 'Ssifizierer', 'type' => Classifier::TYPE_MED,
    ]);
    $admin = admin_cbb();
    $listUrl = listUrl_cbb('classifiers.index', ['type' => Classifier::TYPE_MED]);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)->get(route('classifiers.show', $classifier))->assertOk()->assertSee($listUrl);
    $this->actingAs($admin)->get(route('classifiers.edit', $classifier))->assertOk()
        ->assertSee('href="'.route('classifiers.show', $classifier).'"', false);
    $this->actingAs($admin)->delete(route('classifiers.destroy', $classifier))->assertRedirect($listUrl);
});

// ── Nationen ──────────────────────────────────────────────────────────────────

it('führt bei Nationen zurück zur sortierten Liste und leitet nach dem Speichern dorthin', function () {
    $nation = makeNation_p5();
    $admin = admin_cbb();
    $listUrl = listUrl_cbb('nations.index', ['sort' => 'name_de', 'direction' => 'desc', 'page' => 2]);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)->get(route('nations.edit', $nation))->assertOk()->assertSee($listUrl);
    $this->actingAs($admin)
        ->put(route('nations.update', $nation), ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => '1'])
        ->assertRedirect($listUrl);

    $unused = Nation::create(['code' => 'ZZZ', 'name_de' => 'Z', 'name_en' => 'Z', 'is_active' => true]);
    $this->actingAs($admin)->delete(route('nations.destroy', $unused))->assertRedirect($listUrl);
});

// ── Veranstaltungen ───────────────────────────────────────────────────────────

it('führt von der Veranstaltung zurück zur gefilterten Liste und leitet nach dem Löschen dorthin', function () {
    $meet = makeMeet_p5();
    $admin = admin_cbb();
    $listUrl = listUrl_cbb('meets.index', ['search' => 'Test', 'course' => 'LCM']);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)->get(route('meets.show', $meet))->assertOk()->assertSee($listUrl);
    $this->actingAs($admin)->get(route('meets.create'))->assertOk()->assertSee($listUrl);
    $this->actingAs($admin)->delete(route('meets.destroy', $meet))->assertRedirect($listUrl);
});

// ── Ergebnisse ────────────────────────────────────────────────────────────────

it('führt vom Ergebnis zurück zur gefilterten Ergebnisliste statt zur vorherigen Seite', function () {
    $result = makeResult_cbb();
    $admin = admin_cbb();
    $listUrl = listUrl_cbb('results.index', ['meet_id' => $result->meet_id]);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)
        ->from(route('results.edit', $result))   // "vorherige Seite" wäre das Formular selbst
        ->get(route('results.show', $result))
        ->assertOk()
        ->assertSee($listUrl);

    $this->actingAs($admin)->get(route('results.edit', $result))->assertOk()
        ->assertSee('href="'.route('results.show', $result).'"', false);

    $this->actingAs($admin)->delete(route('results.destroy', $result))->assertRedirect($listUrl);
});

it('führt vom Ergebnis-Anlegen zurück zur Veranstaltung', function () {
    $meet = makeMeet_p5();

    $this->actingAs(admin_cbb())
        ->get(route('meets.results.create', $meet))
        ->assertOk()
        ->assertSee('href="'.route('meets.show', $meet).'"', false);
});

// ── Meldungen ─────────────────────────────────────────────────────────────────

it('führt vom Meldung-Bearbeiten zurück ins gefilterte Cockpit und leitet nach dem Speichern dorthin', function () {
    $entry = makeEntry_cbb();
    $admin = admin_cbb();
    $listUrl = listUrl_cbb('entries.index', ['meet_id' => $entry->meet_id, 'status' => 'open']);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)->get(route('entries.edit', $entry))->assertOk()->assertSee($listUrl);
    $this->actingAs($admin)
        ->put(route('entries.update', $entry), ['club_id' => $entry->club_id])
        ->assertRedirect($listUrl);
});

it('führt vom Meldung-Bearbeiten zurück zu "Alle Meldungen", wenn man von dort kam', function () {
    $entry = makeEntry_cbb();
    $admin = admin_cbb();
    $listUrl = listUrl_cbb('meets.entries-overview', ['meet' => $entry->meet_id, 'event_id' => $entry->swim_event_id]);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)->get(route('entries.edit', $entry))->assertOk()->assertSee($listUrl);
});

it('gibt beim Staffel-Bearbeiten aus "Alle Meldungen" den Rücksprung mit', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $relay = RelayEntry::create([
        'meet_id' => $meet->id,
        'swim_event_id' => makeEvent_p5($meet, ['relay_count' => 4])->id,
        'club_id' => $club->id,
        'relay_class' => 'S20',
        'status' => 'pending',
    ]);
    $admin = admin_cbb();
    $overviewUrl = route('meets.entries-overview', $meet);

    $this->actingAs($admin)
        ->get($overviewUrl)
        ->assertOk()
        ->assertSee('return_to='.urlencode($overviewUrl), false);

    $editUrl = route('club-entries.relay.edit', [
        'meet' => $meet, 'relayEntry' => $relay, 'club_id' => $club->id, 'return_to' => $overviewUrl,
    ]);
    $this->actingAs($admin)
        ->get($editUrl)
        ->assertOk()
        ->assertSee('href="'.$overviewUrl.'"', false)
        ->assertSee('name="return_to"', false);
});

it('ignoriert ein externes return_to beim Staffel-Bearbeiten', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $relay = RelayEntry::create([
        'meet_id' => $meet->id,
        'swim_event_id' => makeEvent_p5($meet, ['relay_count' => 4])->id,
        'club_id' => $club->id,
        'relay_class' => 'S20',
        'status' => 'pending',
    ]);

    $this->actingAs(admin_cbb())
        ->get(route('club-entries.relay.edit', [
            'meet' => $meet, 'relayEntry' => $relay, 'club_id' => $club->id, 'return_to' => 'https://evil.example/',
        ]))
        ->assertOk()
        ->assertDontSee('href="https://evil.example/"', false)
        ->assertSee(route('club-entries.relay.index', ['meet' => $meet, 'club_id' => $club->id]));
});

// ── Athleten (Umstellung auf den gemeinsamen Mechanismus) ─────────────────────

it('führt beim Athleten-Bearbeiten zurück zur Detailseite und nach dem Löschen zur gefilterten Liste', function () {
    $athlete = makeAthlete_p5(makeClub_p5());
    $admin = admin_cbb();
    $listUrl = listUrl_cbb('athletes.index', ['search' => 'Muster', 'page' => 2]);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)->get(route('athletes.show', $athlete))->assertOk()->assertSee($listUrl);
    $this->actingAs($admin)->get(route('athletes.edit', $athlete))->assertOk()
        ->assertSee('href="'.route('athletes.show', $athlete).'"', false);
    $this->actingAs($admin)->delete(route('athletes.destroy', $athlete))->assertRedirect($listUrl);
});

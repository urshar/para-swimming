<?php

use App\Models\Athlete;
use App\Models\AthleteSportClass;
use App\Models\ExceptionCode;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('admin-ui-athletes');

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function makeAdmin_aua(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeNation_aua(string $code, string $nameDe): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $nameDe, 'name_en' => $nameDe, 'is_active' => true]
    );
}

function makeAthlete_aua(array $overrides = []): Athlete
{
    return Athlete::create(array_merge([
        'nation_id' => makeNation_aua('AUT', 'Österreich')->id,
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'gender' => 'M',
        'is_active' => true,
    ], $overrides));
}

function makeExceptionCode_aua(string $code = 'BR3'): ExceptionCode
{
    return ExceptionCode::create([
        'code' => $code,
        'name_de' => 'Brust – Gleichzeitiger Anschlagsversuch',
        'name_en' => 'Breaststroke – Simultaneous touch attempt',
        'is_active' => true,
    ]);
}

// ── Athleten-Liste ──────────────────────────────────────────────────────────

it('filtert nach dem Anfangsbuchstaben des Nachnamens', function () {
    makeAthlete_aua(['first_name' => 'Marion', 'last_name' => 'Adenberger']);
    makeAthlete_aua(['first_name' => 'Fauzi', 'last_name' => 'Bauer']);

    $this->actingAs(makeAdmin_aua())
        ->get(route('athletes.index', ['letter' => 'B']))
        ->assertOk()
        ->assertSee('Bauer')
        ->assertDontSee('Adenberger');
});

it('zeigt bei "Nur inaktive" ausschließlich inaktive Athleten', function () {
    makeAthlete_aua(['first_name' => 'Aktiv', 'last_name' => 'Schwimmer', 'is_active' => true]);
    makeAthlete_aua(['first_name' => 'Inaktiv', 'last_name' => 'Schwimmer', 'is_active' => false]);

    $this->actingAs(makeAdmin_aua())
        ->get(route('athletes.index', ['active_only' => '2']))
        ->assertOk()
        ->assertSee('Schwimmer, Inaktiv')
        ->assertDontSee('Schwimmer, Aktiv');
});

it('sortiert das Nation-Dropdown nach IOC-Code statt nach Name', function () {
    makeNation_aua('GER', 'Deutschland');
    makeNation_aua('AUT', 'Österreich');

    $html = $this->actingAs(makeAdmin_aua())
        ->get(route('athletes.index'))
        ->assertOk()
        ->getContent();

    // "AUT" muss im HTML vor "GER" stehen, obwohl "Deutschland" alphabetisch vor "Österreich" liegt.
    expect(strpos($html, 'AUT'))->toBeLessThan(strpos($html, 'GER'));
});

// ── Formular: Sportklassen/Exceptions nur noch über Klassifikation ──────────

it('zeigt im Neuanlage-Formular keinen Sportklassen- oder Exceptions-Block mehr', function () {
    $this->actingAs(makeAdmin_aua())
        ->get(route('athletes.create'))
        ->assertOk()
        ->assertDontSee('Sport-Klassen')
        ->assertDontSee('WPS Exceptions');
});

it('legt beim Anlegen keine Sportklassen an und leitet zur Klassifikation weiter', function () {
    $nation = makeNation_aua('AUT', 'Österreich');

    $response = $this->actingAs(makeAdmin_aua())->post(route('athletes.store'), [
        'first_name' => 'Neu',
        'last_name' => 'Angelegt',
        'gender' => 'M',
        'nation_id' => $nation->id,
    ]);

    $athlete = Athlete::where('last_name', 'Angelegt')->firstOrFail();

    $response->assertRedirect(route('athletes.show', ['athlete' => $athlete, 'neue_klassifikation' => 1]));
    expect($athlete->sportClasses)->toBeEmpty();
});

it('löscht beim Bearbeiten bestehende Sportklassen und Exceptions NICHT mehr', function () {
    $athlete = makeAthlete_aua();
    AthleteSportClass::create([
        'athlete_id' => $athlete->id,
        'category' => 'S',
        'class_number' => '6',
        'sport_class' => 'S6',
        'classification_scope' => 'NAT',
    ]);
    $athlete->exceptions()->attach(makeExceptionCode_aua()->id, ['category' => null]);

    $this->actingAs(makeAdmin_aua())
        ->put(route('athletes.update', $athlete), [
            'first_name' => $athlete->first_name,
            'last_name' => $athlete->last_name,
            'gender' => $athlete->gender,
            'nation_id' => $athlete->nation_id,
        ])
        ->assertRedirect(route('athletes.show', $athlete));

    $athlete->refresh();
    expect($athlete->sportClasses)->toHaveCount(1)
        ->and($athlete->exceptions)->toHaveCount(1);
});

// ── Klassifikation eintragen ─────────────────────────────────────────────────

it('zeigt bei den Exceptions keine Kategorie-Zuteilung mehr', function () {
    makeExceptionCode_aua();
    $athlete = makeAthlete_aua();

    $this->actingAs(makeAdmin_aua())
        ->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee('exceptions[0][code_id]', false)
        ->assertDontSee('exceptions[0][category]', false);
});

// ── Zurück-Navigation merkt sich die Listen-Ansicht ──────────────────────────

it('merkt sich die zuletzt aufgerufene Athletenliste für den Zurück-Link', function () {
    $athlete = makeAthlete_aua();
    $admin = makeAdmin_aua();

    // Query-Parameter alphabetisch, weil Symfonys Request::fullUrl() die Query-String-Reihenfolge
    // beim Rekonstruieren normalisiert (sortiert) — unabhängig davon, in welcher Reihenfolge der
    // Request tatsächlich gestellt wurde.
    $listUrl = route('athletes.index', ['page' => 2, 'search' => 'Muster']);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)
        ->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee($listUrl);
});

it('fällt ohne vorherigen Listenaufruf auf die normale Athletenliste zurück', function () {
    $athlete = makeAthlete_aua();

    // Frische Session ohne athletes.list_url — Direktlink auf die Detailseite.
    $this->actingAs(makeAdmin_aua())
        ->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee(route('athletes.index'));
});

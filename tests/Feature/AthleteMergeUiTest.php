<?php

use App\Models\Athlete;
use App\Models\AthleteSportClass;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Qualification;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('athlete-merge-p3');

// Athleten zusammenführen, Phase 3: Auswahl, Vorschau und Zusammenführen über die Admin-Oberfläche
// (AthleteMergeController).

function admin_am3(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function nation_am3(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function club_am3(): Club
{
    return Club::firstOrCreate(['name' => 'SV Gallneukirchen'], ['nation_id' => nation_am3()->id]);
}

function athlete_am3(string $lastName, string $firstName, array $attributes): Athlete
{
    return Athlete::create(['last_name' => $lastName, 'first_name' => $firstName, 'gender' => 'F',
        'birth_date' => '1990-08-21', 'nation_id' => nation_am3()->id, 'club_id' => club_am3()->id, ...$attributes]);
}

function result_am3(Athlete $athlete, SwimEvent $event): Result
{
    return Result::create(['meet_id' => $event->meet_id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => club_am3()->id, 'sport_class' => 'S9', 'swim_time' => 3300]);
}

function event_am3(int $number): SwimEvent
{
    $meet = Meet::firstOrCreate(['name' => 'Staatsmeisterschaften'],
        ['nation_id' => nation_am3()->id, 'course' => 'SCM', 'start_date' => '2026-05-10']);
    $stroke = StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']);

    return SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 50 * $number,
        'gender' => 'F', 'event_number' => $number, 'relay_count' => 1]);
}

// ── Zugriff und Einstieg ─────────────────────────────────────────────────────

it('verwehrt Vereinsnutzern Auswahl, Vorschau und Zusammenführen', function () {
    $keep = athlete_am3('Djuza', 'Slavica', []);
    $drop = athlete_am3('Slavica', 'Djuza', []);
    $user = User::factory()->create(['is_admin' => false, 'club_id' => club_am3()->id]);

    $this->actingAs($user)->get(route('athletes.merge.create', $keep))->assertForbidden();
    $this->actingAs($user)->get(route('athletes.merge.preview', [$keep, $drop]))->assertForbidden();
    $this->actingAs($user)->post(route('athletes.merge.store', [$keep, $drop]))->assertForbidden();

    expect(Athlete::find($drop->id))->not->toBeNull();
});

it('zeigt Admins auf der Athletenseite den Einstieg und auf der Auswahlseite alle anderen Athleten', function () {
    $athlete = athlete_am3('Djuza', 'Slavica', ['license' => 'O-2030']);
    athlete_am3('Slavica', 'Djuza', ['license' => 'O-2030']);
    $admin = admin_am3();

    $this->actingAs($admin)->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee(route('athletes.merge.create', $athlete));

    $this->actingAs($admin)->get(route('athletes.merge.create', $athlete))
        ->assertOk()
        ->assertSee('Slavica, Djuza')
        ->assertSee('21.08.1990')
        ->assertSee('O-2030');
});

// ── Vorschau ─────────────────────────────────────────────────────────────────

it('zeigt in der Vorschau abweichende Stammdaten mit Vorschlag, Daten, Sportklassen und den Tausch-Link', function () {
    $keep = athlete_am3('Djuza', 'Slavica', []);
    $drop = athlete_am3('Slavica', 'Djuza', ['license' => 'O-2030']);
    result_am3($drop, event_am3(1));
    AthleteSportClass::create(['athlete_id' => $keep->id, 'category' => 'S', 'class_number' => '9', 'sport_class' => 'S9']);
    AthleteSportClass::create(['athlete_id' => $drop->id, 'category' => 'S', 'class_number' => '8', 'sport_class' => 'S8']);

    $response = $this->actingAs(admin_am3())->get(route('athletes.merge.preview', [$keep, $drop]));

    expect($response->getContent())
        ->toMatch('/name="fields\[license\]" value="drop"\s+class="[^"]*"\s+checked/')
        ->not->toMatch('/name="fields\[first_name\]" value="drop"\s+class="[^"]*"\s+checked/');

    $response->assertOk()
        ->assertSeeInOrder(['Lizenznummer', '–', 'O-2030'])
        ->assertSee('Ergebnisse')
        ->assertSee('Kategorie S')
        ->assertSee('S8 (INTL) (aufgelöst)')
        ->assertSee(route('athletes.merge.preview', [$drop, $keep]))
        ->assertSee('Keine Cup-Wertung und keine Qualifikationen betroffen.');
});

it('sperrt das Zusammenführen bei blockierenden Kollisionen', function () {
    $keep = athlete_am3('Djuza', 'Slavica', []);
    $drop = athlete_am3('Slavica', 'Djuza', []);
    $event = event_am3(1);
    result_am3($keep, $event);
    result_am3($drop, $event);

    $this->actingAs(admin_am3())->get(route('athletes.merge.preview', [$keep, $drop]))
        ->assertOk()
        ->assertSee('Zusammenführen nicht möglich')
        ->assertSee('Beide haben ein Ergebnis in &quot;Staatsmeisterschaften&quot;, Bewerb 1.', false);

    $this->actingAs(admin_am3())->post(route('athletes.merge.store', [$keep, $drop]))
        ->assertRedirect(route('athletes.merge.preview', [$keep, $drop]))
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Bewerb 1'));

    expect(Athlete::find($drop->id))->not->toBeNull();
});

it('nennt in der Vorschau die Richtzeitenlisten zur Neuberechnung samt offenem Zeitraum', function () {
    $keep = athlete_am3('Djuza', 'Slavica', []);
    $drop = athlete_am3('Slavica', 'Djuza', []);
    $list = QualifyingTimeList::create(['year' => 2026, 'is_active' => true, 'qualification_period_start' => '2026-01-01']);
    $time = QualifyingTime::create(['qualifying_time_list_id' => $list->id, 'stroke_type_id' => event_am3(1)->stroke_type_id,
        'distance' => 50, 'gender' => 'F', 'sport_class' => 'S9', 'value_centiseconds' => 4000,
        'source' => QualifyingTime::SOURCE_CALCULATED]);
    Qualification::create(['qualifying_time_list_id' => $list->id, 'qualifying_time_id' => $time->id,
        'athlete_id' => $drop->id, 'result_id' => result_am3($drop, event_am3(2))->id, 'sport_class' => 'S9',
        'swim_time_centiseconds' => 3300, 'qualified_at' => '2026-05-10']);

    $this->actingAs(admin_am3())->get(route('athletes.merge.preview', [$keep, $drop]))
        ->assertOk()
        ->assertSee('Qualifikationen der Richtzeitenliste 2026')
        ->assertSee('Zeitraum offen – wird bis heute gerechnet')
        ->assertSee('name="recalculate"', false);
});

// ── Zusammenführen ───────────────────────────────────────────────────────────

it('führt mit der getroffenen Auswahl zusammen und leitet zum behaltenen Athleten weiter', function () {
    $keep = athlete_am3('Djuza', 'Slavica', []);
    $drop = athlete_am3('Slavica', 'Djuza', ['license' => 'O-2030', 'birth_date' => '1990-08-22']);
    $result = result_am3($drop, event_am3(1));

    $this->actingAs(admin_am3())
        ->post(route('athletes.merge.store', [$keep, $drop]), [
            'fields' => ['first_name' => 'keep', 'last_name' => 'keep', 'license' => 'drop', 'birth_date' => 'drop'],
            'recalculate' => '1',
        ])
        ->assertRedirect(route('athletes.show', $keep))
        ->assertSessionHas('success', "#$drop->id Slavica, Djuza wurde in #$keep->id Djuza, Slavica übernommen und gelöscht (übernommen: 1 Ergebnisse).");

    $keep->refresh();

    expect(Athlete::withTrashed()->find($drop->id))->toBeNull()
        ->and($result->fresh()->athlete_id)->toBe($keep->id)
        ->and($keep->license)->toBe('O-2030')
        ->and($keep->birth_date->toDateString())->toBe('1990-08-22')
        ->and($keep->display_name)->toBe('Djuza, Slavica');
});

it('bietet einen abweichenden Verein zur Auswahl an und übernimmt den gewählten', function () {
    $other = Club::create(['name' => 'AG Down Syndrom', 'nation_id' => nation_am3()->id]);
    $keep = athlete_am3('Kopfer', 'Nina', []);
    $drop = athlete_am3('Kopfer', 'Nina', ['club_id' => $other->id]);

    $this->actingAs(admin_am3())->get(route('athletes.merge.preview', [$keep, $drop]))
        ->assertOk()
        ->assertSeeInOrder(['Verein', 'SV Gallneukirchen', 'AG Down Syndrom']);

    $this->actingAs(admin_am3())
        ->post(route('athletes.merge.store', [$keep, $drop]), ['fields' => ['club_id' => 'drop']])
        ->assertRedirect(route('athletes.show', $keep));

    expect($keep->fresh()->club_id)->toBe($other->id);
});

it('verlangt eine Auswahl für abweichende Sportklassen', function () {
    $keep = athlete_am3('Djuza', 'Slavica', []);
    $drop = athlete_am3('Slavica', 'Djuza', []);
    AthleteSportClass::create(['athlete_id' => $keep->id, 'category' => 'S', 'class_number' => '9', 'sport_class' => 'S9']);
    AthleteSportClass::create(['athlete_id' => $drop->id, 'category' => 'S', 'class_number' => '8', 'sport_class' => 'S8']);

    $this->actingAs(admin_am3())
        ->post(route('athletes.merge.store', [$keep, $drop]), ['fields' => ['first_name' => 'keep']])
        ->assertRedirect(route('athletes.merge.preview', [$keep, $drop]))
        ->assertSessionHasErrors('sport_classes.S');

    expect(Athlete::find($drop->id))->not->toBeNull();
});

it('lehnt eine ungültige Auswahl ab', function () {
    $keep = athlete_am3('Djuza', 'Slavica', []);
    $drop = athlete_am3('Slavica', 'Djuza', ['license' => 'O-2030']);

    $this->actingAs(admin_am3())
        ->post(route('athletes.merge.store', [$keep, $drop]), ['fields' => ['license' => 'both']])
        ->assertRedirect(route('athletes.merge.preview', [$keep, $drop]))
        ->assertSessionHasErrors('fields');

    $this->actingAs(admin_am3())
        ->post(route('athletes.merge.store', [$keep, $drop]), ['fields' => ['license' => 'beliebig']])
        ->assertSessionHasErrors('fields.license');

    expect(Athlete::find($drop->id))->not->toBeNull();
});

it('liefert 404, wenn ein Athlet mit sich selbst zusammengeführt werden soll', function () {
    $athlete = athlete_am3('Djuza', 'Slavica', []);

    $this->actingAs(admin_am3())->get(route('athletes.merge.preview', [$athlete, $athlete]))->assertNotFound();
});

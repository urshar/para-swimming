<?php

use App\Models\Athlete;
use App\Models\AthleteDuplicateDismissal;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\AthleteDuplicateFinder;
use App\Services\AthleteMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('athlete-merge-p4');

// Athleten zusammenführen, Phase 4: Dublettenvorschläge (AthleteDuplicateFinder), "kein Duplikat" und der Rückweg
// von der Vorschau zur Dublettenliste.

function admin_am4(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function nation_am4(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function club_am4(): Club
{
    return Club::firstOrCreate(['name' => 'Heimverein'], ['nation_id' => nation_am4()->id]);
}

function athlete_am4(string $lastName, string $firstName, ?string $birthDate, array $attributes): Athlete
{
    return Athlete::create(['last_name' => $lastName, 'first_name' => $firstName, 'gender' => 'F',
        'birth_date' => $birthDate, 'nation_id' => nation_am4()->id, 'club_id' => club_am4()->id, ...$attributes]);
}

function result_am4(Athlete $athlete): Result
{
    $meet = Meet::firstOrCreate(['name' => 'Staatsmeisterschaften'],
        ['nation_id' => nation_am4()->id, 'course' => 'SCM', 'start_date' => '2026-05-10']);
    $stroke = StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']);
    $event = SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 50,
        'gender' => 'F', 'event_number' => SwimEvent::count() + 1, 'relay_count' => 1]);

    return Result::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => club_am4()->id, 'sport_class' => 'S9', 'swim_time' => 3300]);
}

/** Vorschläge kompakt: "Stufe: #behalten → #aufgelöst (Gründe)". */
function summary_am4(bool $withDismissed): array
{
    return array_map(
        fn (array $pair) => $pair['strength'].': #'.$pair['keep']->id.' → #'.$pair['drop']->id
            .' ('.implode(', ', $pair['reasons']).')',
        app(AthleteDuplicateFinder::class)->find($withDismissed),
    );
}

// ── Regeln ───────────────────────────────────────────────────────────────────

it('findet Dubletten nach Lizenz, Name und Geburtsdatum und sortiert nach Stärke', function () {
    $sameDate = '1990-08-21';
    $weakA = athlete_am4('Schmid', 'Patrick', '1987-11-18', ['gender' => 'M']);
    $weakB = athlete_am4('Schmid', 'Manuel', '1987-11-18', ['gender' => 'M']);
    $mediumA = athlete_am4('Muhrhofer', 'Katrin', '1950-01-01', []);
    $mediumB = athlete_am4('Muhrhofer', 'Katrin', '1979-01-03', []);
    $strongA = athlete_am4('Djuza', 'Slavica', $sameDate, ['license' => 'O-2030']);
    $strongB = athlete_am4('Slavica', 'Djuza', $sameDate, ['license' => 'O-2030']);
    $nameA = athlete_am4('Kopfer', 'Nina', '2002-12-20', []);
    $nameB = athlete_am4('KOPFER ', 'nina', '2002-12-20', []);
    // Platzhalter-Lizenz ohne Ziffer und gleiches Geburtsdatum ohne gemeinsamen Namensteil: kein Vorschlag.
    athlete_am4('Bauer', 'Alexander', '1999-01-01', ['license' => 'W-']);
    athlete_am4('Huber', 'Maria', '1999-01-01', ['license' => 'W-']);

    expect(summary_am4(false))->toBe([
        "strong: #$strongA->id → #$strongB->id (Gleiche Lizenz, Vertauschter Vor- und Nachname)",
        "strong: #$nameA->id → #$nameB->id (Gleicher Name und Geburtsdatum)",
        "medium: #$mediumA->id → #$mediumB->id (Gleicher Name, anderes Geburtsdatum)",
        "weak: #$weakA->id → #$weakB->id (Gleiches Geburtsdatum, gemeinsamer Namensteil)",
    ]);
});

it('schlägt als bleibenden Athleten den mit mehr Daten vor', function () {
    $older = athlete_am4('Teuchmann', 'Christina', '1991-02-10', []);
    $newer = athlete_am4('Teuchmann', 'Christine', '1991-02-10', []);
    result_am4($newer);

    expect(summary_am4(false))->toBe([
        "weak: #$newer->id → #$older->id (Gleiches Geburtsdatum, gemeinsamer Namensteil)",
    ]);
});

it('berücksichtigt kein Geschlecht übergreifendes Paar mit gleichem Geburtsdatum', function () {
    athlete_am4('Vrzal', 'Martin', '1990-09-12', ['gender' => 'M']);
    athlete_am4('Vrzal', 'Renate', '1990-09-12', ['gender' => 'F']);

    expect(summary_am4(false))->toBe([]);
});

// ── Seite und "kein Duplikat" ────────────────────────────────────────────────

it('zeigt Admins die Vorschläge mit Link zur Vorschau, Vereinsnutzern nicht', function () {
    $keep = athlete_am4('Djuza', 'Slavica', '1990-08-21', ['license' => 'O-2030']);
    $drop = athlete_am4('Slavica', 'Djuza', '1990-08-21', ['license' => 'O-2030']);

    $this->actingAs(admin_am4())->get(route('athletes.index'))
        ->assertSee(route('athletes.duplicates.index'));

    $this->actingAs(admin_am4())->get(route('athletes.duplicates.index'))
        ->assertOk()
        ->assertSee('1 Vorschlag')
        ->assertSee('Gleiche Lizenz · Vertauschter Vor- und Nachname')
        ->assertSee(route('athletes.merge.preview', [$keep, $drop, 'from' => 'duplicates']), false);

    $clubUser = User::factory()->create(['is_admin' => false, 'club_id' => club_am4()->id]);
    $this->actingAs($clubUser)->get(route('athletes.duplicates.index'))->assertForbidden();
    $this->actingAs($clubUser)->post(route('athletes.duplicates.dismiss'),
        ['athlete_id' => $keep->id, 'other_athlete_id' => $drop->id])->assertForbidden();
});

it('blendet als "kein Duplikat" markierte Paare aus und nimmt die Markierung wieder zurück', function () {
    $first = athlete_am4('Schmid', 'Patrick', '1987-11-18', ['gender' => 'M']);
    $second = athlete_am4('Schmid', 'Manuel', '1987-11-18', ['gender' => 'M']);
    $admin = admin_am4();

    $this->actingAs($admin)
        ->post(route('athletes.duplicates.dismiss'), ['athlete_id' => $second->id, 'other_athlete_id' => $first->id])
        ->assertRedirect(route('athletes.duplicates.index'))
        ->assertSessionHas('success', "#$first->id Schmid, Patrick und #$second->id Schmid, Manuel als \"kein Duplikat\" markiert.");

    $dismissal = AthleteDuplicateDismissal::sole();

    expect($dismissal->athlete_id)->toBe($first->id)
        ->and($dismissal->other_athlete_id)->toBe($second->id)
        ->and($dismissal->dismissed_by)->toBe($admin->id)
        ->and(summary_am4(false))->toBe([])
        ->and(summary_am4(true))->toHaveCount(1);

    $this->actingAs($admin)->get(route('athletes.duplicates.index', ['dismissed' => 1]))
        ->assertOk()
        ->assertSee('als &quot;kein Duplikat&quot; markiert', false);

    // Zurück zur gemerkten Liste, hier samt Filter "Ausgeblendete anzeigen".
    $this->actingAs($admin)->delete(route('athletes.duplicates.restore', $dismissal))
        ->assertRedirect(route('athletes.duplicates.index', ['dismissed' => 1]));

    expect(AthleteDuplicateDismissal::count())->toBe(0)
        ->and(summary_am4(false))->toHaveCount(1);
});

it('lehnt eine Markierung eines Athleten mit sich selbst ab', function () {
    $athlete = athlete_am4('Schmid', 'Patrick', '1987-11-18', []);

    $this->actingAs(admin_am4())
        ->post(route('athletes.duplicates.dismiss'), ['athlete_id' => $athlete->id, 'other_athlete_id' => $athlete->id])
        ->assertSessionHasErrors('other_athlete_id');

    expect(AthleteDuplicateDismissal::count())->toBe(0);
});

it('entfernt Markierungen des aufgelösten Athleten beim Zusammenführen', /** @throws Throwable */ function () {
    $keep = athlete_am4('Djuza', 'Slavica', '1990-08-21', []);
    $drop = athlete_am4('Slavica', 'Djuza', '1990-08-21', []);
    $other = athlete_am4('Andere', 'Person', '1990-08-21', []);
    AthleteDuplicateDismissal::create(['athlete_id' => $drop->id, 'other_athlete_id' => $other->id]);

    app(AthleteMergeService::class)->merge($keep, $drop, [], [], false);

    expect(AthleteDuplicateDismissal::count())->toBe(0);
});

// ── Rückweg ──────────────────────────────────────────────────────────────────

it('führt von der Dublettenliste aus über Vorschau und Zusammenführen wieder zur Liste zurück', function () {
    $keep = athlete_am4('Djuza', 'Slavica', '1990-08-21', ['license' => 'O-2030']);
    $drop = athlete_am4('Slavica', 'Djuza', '1990-08-21', ['license' => 'O-2030']);
    $admin = admin_am4();
    $listUrl = route('athletes.duplicates.index', ['dismissed' => 1]);
    $this->actingAs($admin)->get($listUrl)->assertOk();

    $this->actingAs($admin)->get(route('athletes.merge.preview', [$keep, $drop, 'from' => 'duplicates']))
        ->assertOk()
        ->assertSee('href="'.e($listUrl).'"', false)
        ->assertSee('name="from" value="duplicates"', false)
        ->assertSee(route('athletes.merge.preview', [$drop, $keep, 'from' => 'duplicates']), false);

    $this->actingAs($admin)
        ->post(route('athletes.merge.store', [$keep, $drop]), ['from' => 'duplicates'])
        ->assertRedirect($listUrl);

    expect(Athlete::withTrashed()->find($drop->id))->toBeNull();
});

it('bleibt ohne Herkunft Dublettenliste beim Rückweg zur Athletenseite', function () {
    $keep = athlete_am4('Djuza', 'Slavica', '1990-08-21', []);
    $drop = athlete_am4('Slavica', 'Djuza', '1990-08-21', []);

    $this->actingAs(admin_am4())->get(route('athletes.merge.preview', [$keep, $drop]))
        ->assertOk()
        ->assertDontSee('name="from"', false);
});

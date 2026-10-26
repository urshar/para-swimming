<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\ResultSplit;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('split-records-p3');

// Anzeige (docs/specs/split-records.md, Phase 3): Herkunft "Zwischenzeit" und ungültig gewordene Rekorde in
// Rekordliste, Rekord-Detail und Prüfergebnis des Rekord-Checks.

function nation_spd(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function stroke_spd(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'],
        ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE', 'is_active' => true]);
}

/** Nationaler Einzelrekord 50 m Freistil SCM; der Ort dient als eindeutige Kennung der Zeile. */
function record_spd(string $city, array $attributes = []): SwimRecord
{
    return SwimRecord::create([
        'stroke_type_id' => stroke_spd()->id, 'nation_id' => nation_spd()->id, 'record_type' => 'AUT',
        'sport_class' => 'S9', 'gender' => 'M', 'course' => 'SCM', 'distance' => 50, 'relay_count' => 1,
        'swim_time' => 3000, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => '2018-05-05',
        'meet_city' => $city, ...$attributes,
    ]);
}

function admin_spd(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Rekordliste national, Einzel, S9, SCM, mit den übrigen Filtern aus $query. */
function list_spd(array $query): string
{
    return test()->actingAs(admin_spd())
        ->get(route('records.index', ['category' => 'national', 'relay' => 'single', 'sport_class' => 'S9,SB9,SM9',
            'course' => 'SCM', ...$query]))
        ->assertOk()
        ->getContent();
}

it('kennzeichnet Rekorde aus Zwischenzeiten in der Rekordliste', function () {
    record_spd('Splitort', ['from_split' => true, 'record_status' => 'PENDING',
        'comment' => 'Zwischenzeit aus 100 m Freistil']);

    $html = list_spd([]);

    expect($html)->toContain('Splitort')
        ->toMatch('/data-flux-badge[^>]*>\s*Zwischenzeit\s*</');
});

it('zeigt ungültig gewordene Rekorde nur im Statusfilter "Ungültig", mit Begründung', function () {
    record_spd('Geltendort');
    record_spd('Ungültigort', ['record_status' => 'INVALID', 'is_current' => false,
        'comment' => 'Ungültig: älterer Rekord 0:29,50 vom 05.05.2012']);

    $all = list_spd([]);
    $invalid = list_spd(['status' => 'INVALID']);

    expect($all)->toContain('Geltendort')
        ->not->toContain('Ungültigort')
        ->and($invalid)->toContain('Ungültigort')
        ->toContain('Ungültig: älterer Rekord 0:29,50 vom 05.05.2012')
        ->not->toContain('Geltendort');
});

it('zeigt im Rekord-Detail die Herkunft aus einer Zwischenzeit', function () {
    $record = record_spd('Splitort', ['from_split' => true, 'comment' => 'Zwischenzeit aus 100 m Freistil']);

    $this->actingAs(admin_spd())->get(route('records.show', $record))
        ->assertOk()
        ->assertSee('Zwischenzeit aus 100 m Freistil')
        ->assertSee('Rekord aus einer Zwischenzeit');
});

it('zeigt einen ausstehenden Rekord im Detail nicht als aktuellen Rekord', function () {
    $current = record_spd('Geltendort', ['swim_time' => 3100]);
    $pending = record_spd('Splitort', ['from_split' => true, 'record_status' => 'PENDING', 'supersedes_id' => $current->id]);

    $this->actingAs(admin_spd())->get(route('records.show', $pending))
        ->assertOk()
        ->assertSee('Aufgestellt am 05.05.2018')
        ->assertDontSee('Aktueller Rekord seit')
        ->assertSeeInOrder(['Rekord-Historie', 'Aktuell', 'Zwischenzeit']);
});

it('zeigt im Rekord-Detail, dass ein ungültiger Rekord nicht mehr in der Kette ist', function () {
    $record = record_spd('Ungültigort', ['record_status' => 'INVALID', 'is_current' => false]);

    $this->actingAs(admin_spd())->get(route('records.show', $record))
        ->assertOk()
        ->assertSee('Nicht in der Rekordkette')
        ->assertSee('Aufgestellt am 05.05.2018')
        ->assertDontSee('Aktueller Rekord');
});

it('kennzeichnet ausstehende Zwischenzeit-Rekorde im Prüfergebnis des Rekord-Checks', function () {
    $austria = nation_spd();
    $club = Club::query()->create(['name' => 'Verein', 'nation_id' => $austria->id, 'type' => 'CLUB']);
    $meet = Meet::query()->create(['name' => 'ÖSTM', 'start_date' => '2018-05-05', 'end_date' => '2018-05-05',
        'course' => 'SCM', 'city' => 'Graz', 'nation_id' => $austria->id, 'timing' => 'AUTOMATIC']);
    $event = SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_spd()->id,
        'distance' => 100, 'relay_count' => 1, 'gender' => 'M', 'session_number' => 1, 'event_number' => 1,
        'round' => 'TIM']);
    $athlete = Athlete::query()->create(['first_name' => 'Max', 'last_name' => 'Muster', 'gender' => 'M',
        'birth_date' => '1980-01-01', 'nation_id' => $austria->id, 'club_id' => $club->id]);
    $result = Result::query()->create(['meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'sport_class' => 'S9', 'swim_time' => 6200, 'heat' => 1]);
    ResultSplit::query()->create(['result_id' => $result->id, 'distance' => 50, 'split_time' => 2950]);
    $admin = admin_spd();
    $show = route('meets.show', $meet);

    $this->actingAs($admin)->from($show)->post(route('records.check', $meet))->assertRedirect($show);

    $this->actingAs($admin)->get($show)
        ->assertSee('Ausstehende Rekorde')
        ->assertSee('Zwischenzeit – Bestätigung durch den Verband nötig')
        ->assertSeeInOrder(['Ausstehende Rekorde', 'Zwischenzeit']);
});

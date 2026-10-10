<?php

use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('record-relay-filter');

// Rekordlisten: Staffelklassen inkl. Brust/Lagen (SB/SM), Wertung Mixed als Filter und Anzeige.

function stroke_rfr(string $code, string $name): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $code],
        ['name_de' => $name, 'name_en' => $name, 'code' => $code, 'is_active' => true]);
}

/** Aktueller nationaler Staffelrekord; der Ort dient im Test als eindeutige Kennung der Zeile. */
function relay_rfr(string $sportClass, string $gender, StrokeType $stroke, string $city): SwimRecord
{
    $aut = Nation::firstOrCreate(['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);

    return SwimRecord::create([
        'stroke_type_id' => $stroke->id, 'nation_id' => $aut->id, 'record_type' => 'AUT',
        'sport_class' => $sportClass, 'gender' => $gender, 'course' => 'SCM', 'distance' => 50, 'relay_count' => 4,
        'swim_time' => 20000, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => '2024-05-05',
        'meet_city' => $city,
    ]);
}

function admin_rfr(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Rekordliste national, Staffeln, SCM, mit den übrigen Filtern aus $query. */
function list_rfr(array $query): string
{
    return test()->actingAs(admin_rfr())
        ->get(route('records.index', ['category' => 'national', 'relay' => 'relay', 'course' => 'SCM', ...$query]))
        ->assertOk()
        ->getContent();
}

beforeEach(function () {
    $free = stroke_rfr('FREE', 'Freistil');
    $breast = stroke_rfr('BREAST', 'Brust');
    relay_rfr('S49', 'M', $free, 'Freistilort');
    relay_rfr('SB49', 'M', $breast, 'Brustort');
    relay_rfr('S34', 'X', $free, 'Mixedort');
});

it('bietet die Staffelklassen inklusive Brust und Lagen an und filtert danach', function () {
    $html = list_rfr(['sport_class' => 'S49,SB49,SM49']);

    expect($html)->toContain('S49,SB49,SM49')
        ->toContain('Freistilort')
        ->toContain('Brustort')
        ->not->toContain('Mixedort');
});

it('filtert Staffeln nach Mixed und zeigt Mixed nicht als Damen an', function () {
    $filtered = list_rfr(['gender' => 'X']);
    $all = list_rfr([]);

    // Wertungs-Badges der Tabellenzeilen (Dropdown-Optionen sind keine Badges).
    preg_match_all('/<div[^>]*data-flux-badge[^>]*>\s*(Herren|Damen|Mixed)\s*</', $all, $badges);

    expect($filtered)->toContain('Mixedort')
        ->not->toContain('Freistilort')
        ->and($badges[1])->toEqualCanonicalizing(['Herren', 'Herren', 'Mixed']);
});

it('ignoriert den Mixed-Filter bei Einzelrekorden', function () {
    $html = test()->actingAs(admin_rfr())
        ->get(route('records.index', ['category' => 'national', 'relay' => 'single', 'gender' => 'X']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('value="X"');
});

it('zeigt die Wertung Mixed auf der Detailseite', function () {
    $record = SwimRecord::where('gender', 'X')->sole();

    $this->actingAs(admin_rfr())
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertSee('Mixed')
        ->assertDontSee('Damen');
});

it('bietet bei Einzel die Sportklassen auch ohne importierte Basiszeiten an', function () {
    $html = test()->actingAs(admin_rfr())
        ->get(route('records.index', ['category' => 'national', 'relay' => 'single', 'course' => 'SCM']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('S01,SB01,SM01')
        ->toContain('S14,SB14,SM14')
        ->toContain('S21,SB21,SM21');
});

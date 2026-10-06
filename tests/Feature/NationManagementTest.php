<?php

use App\Models\Athlete;
use App\Models\Classifier;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('nations-add-delete');

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function makeAdmin_nat1(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeNation_nat1(string $code = 'XYZ'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => 'Testland', 'name_en' => 'Testland', 'is_active' => true]
    );
}

function makeRecord_nat1(array $overrides): SwimRecord
{
    $stroke = StrokeType::firstOrCreate(
        ['lenex_code' => 'FREE'],
        ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']
    );

    return SwimRecord::create(array_merge([
        'stroke_type_id' => $stroke->id,
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
    ], $overrides));
}

// ── Anlegen ───────────────────────────────────────────────────────────────────

it('zeigt den Neu-Button in der Nationenliste und das Anlege-Formular', function () {
    $admin = makeAdmin_nat1();

    $this->actingAs($admin)
        ->get(route('nations.index'))
        ->assertOk()
        ->assertSee('Neue Nation')
        ->assertSee(route('nations.create'));

    $this->actingAs($admin)
        ->get(route('nations.create'))
        ->assertOk()
        ->assertSee('IOC-Code');
});

it('legt eine Nation an und normalisiert den Code auf Großbuchstaben', function () {
    $this->actingAs(makeAdmin_nat1())
        ->post(route('nations.store'), [
            'code' => ' kos ',
            'name_de' => 'Kosovo',
            'name_en' => 'Kosovo',
            'is_active' => '1',
        ])
        ->assertRedirect(route('nations.index'))
        ->assertSessionHas('success', 'Nation KOS angelegt.');

    $nation = Nation::where('code', 'KOS')->first();

    expect($nation)->not->toBeNull()
        ->and($nation->name_de)->toBe('Kosovo')
        ->and($nation->is_active)->toBeTrue();
});

it('legt eine Nation ohne Aktiv-Schalter als inaktiv an', function () {
    $this->actingAs(makeAdmin_nat1())
        ->post(route('nations.store'), ['code' => 'ABC', 'name_de' => 'Abc', 'name_en' => 'Abc'])
        ->assertRedirect(route('nations.index'));

    expect(Nation::where('code', 'ABC')->value('is_active'))->toBeFalsy();
});

it('lehnt einen bereits vorhandenen Code ab (auch in Kleinbuchstaben)', function () {
    makeNation_nat1('AUT');

    $this->actingAs(makeAdmin_nat1())
        ->from(route('nations.create'))
        ->post(route('nations.store'), ['code' => 'aut', 'name_de' => 'Doppelt', 'name_en' => 'Duplicate'])
        ->assertRedirect(route('nations.create'))
        ->assertSessionHasErrors(['code' => 'Eine Nation mit diesem IOC-Code existiert bereits.']);

    expect(Nation::where('code', 'AUT')->count())->toBe(1);
});

it('lehnt Codes mit falschem Format ab', function (string $code) {
    $this->actingAs(makeAdmin_nat1())
        ->post(route('nations.store'), ['code' => $code, 'name_de' => 'X', 'name_en' => 'X'])
        ->assertSessionHasErrors('code');

    expect(Nation::count())->toBe(0);
})->with(['zu kurz' => 'AU', 'zu lang' => 'AUTX', 'Ziffer' => 'A1B', 'leer' => '']);

it('verlangt deutschen und englischen Namen', function () {
    $this->actingAs(makeAdmin_nat1())
        ->post(route('nations.store'), ['code' => 'ABC'])
        ->assertSessionHasErrors(['name_de', 'name_en']);
});

// ── Löschen ───────────────────────────────────────────────────────────────────

it('löscht eine Nation, auf die nichts verweist', function () {
    $nation = makeNation_nat1();

    $this->actingAs(makeAdmin_nat1())
        ->delete(route('nations.destroy', $nation))
        ->assertRedirect(route('nations.index'))
        ->assertSessionHas('success', 'Nation XYZ gelöscht.');

    expect(Nation::find($nation->id))->toBeNull();
});

it('blockiert das Löschen, solange Datensätze auf die Nation verweisen', function (Closure $attach, string $expected) {
    $nation = makeNation_nat1();
    $attach($nation);

    $this->actingAs(makeAdmin_nat1())
        ->from(route('nations.index'))
        ->delete(route('nations.destroy', $nation))
        ->assertRedirect(route('nations.index'))
        ->assertSessionHas('error');

    expect(Nation::find($nation->id))->not->toBeNull()
        ->and(session('error'))->toContain($expected);
})->with([
    'Athlet' => [fn (Nation $n) => Athlete::create([
        'nation_id' => $n->id, 'first_name' => 'Max', 'last_name' => 'Muster', 'gender' => 'M',
    ]), '1 Athlet'],
    'Verein' => [fn (Nation $n) => Club::create(['name' => 'Testverein', 'nation_id' => $n->id]), '1 Verein'],
    'Veranstaltung' => [fn (Nation $n) => Meet::create([
        'name' => 'Testmeet', 'nation_id' => $n->id, 'start_date' => '2026-05-01',
    ]), '1 Veranstaltung'],
    'Rekord' => [fn (Nation $n) => makeRecord_nat1(['nation_id' => $n->id]), '1 Rekord'],
    'Rekord (Veranstaltungsland)' => [
        fn (Nation $n) => makeRecord_nat1(['meet_nation_id' => $n->id]), '1 Rekord (als Veranstaltungsland)',
    ],
    'Klassifizierer' => [fn (Nation $n) => Classifier::create([
        'first_name' => 'Kla', 'last_name' => 'Ssifizierer', 'type' => Classifier::TYPE_MED, 'nation_id' => $n->id,
    ]), '1 Klassifizierer'],
]);

it('zählt auch soft-gelöschte Datensätze und nennt mehrere Arten im Plural', function () {
    $nation = makeNation_nat1();
    Club::create(['name' => 'Verein A', 'nation_id' => $nation->id])->delete();
    Club::create(['name' => 'Verein B', 'nation_id' => $nation->id]);
    Athlete::create(['nation_id' => $nation->id, 'first_name' => 'A', 'last_name' => 'B', 'gender' => 'F']);

    $this->actingAs(makeAdmin_nat1())
        ->delete(route('nations.destroy', $nation))
        ->assertSessionHas('error');

    expect(Nation::find($nation->id))->not->toBeNull()
        ->and(session('error'))->toContain('1 Athlet, 2 Vereine');
});

it('zeigt den Lösch-Hinweis auf der Nationenliste an', function () {
    $nation = makeNation_nat1();
    Club::create(['name' => 'Testverein', 'nation_id' => $nation->id]);
    $admin = makeAdmin_nat1();

    $this->actingAs($admin)
        ->from(route('nations.index'))
        ->followingRedirects()
        ->delete(route('nations.destroy', $nation))
        ->assertOk()
        ->assertSee('Nation XYZ kann nicht gelöscht werden', false)
        ->assertSee('1 Verein');
});

// ── Zugriff ───────────────────────────────────────────────────────────────────

it('leitet Gäste bei Anlegen und Löschen zum Login um', function () {
    $nation = makeNation_nat1();

    $this->post(route('nations.store'), ['code' => 'ABC', 'name_de' => 'X', 'name_en' => 'X'])
        ->assertRedirect(route('login'));
    $this->delete(route('nations.destroy', $nation))->assertRedirect(route('login'));

    expect(Nation::count())->toBe(1);
});

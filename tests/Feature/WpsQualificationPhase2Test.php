<?php

use App\Livewire\Admin\ChampionshipStandardTable;
use App\Models\BaseTimeSportClass;
use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\StrokeType;
use App\Models\User;
use App\Models\WpsPointParameter;
use App\Models\WpsPointVersion;
use App\Services\ChampionshipStandardService;
use App\Services\WpsPointCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('wps-qual-p2');

// ── Helper (Suffix _wq2 gegen Namenskollisionen) ─────────────────────────────

function admin_wq2(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function clubUser_wq2(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function stroke_wq2(string $code): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => $code],
        ['lenex_code' => $code, 'name_de' => $code, 'name_en' => $code]
    );
}

function championship_wq2(array $abweichungen): Championship
{
    return Championship::query()->create(array_merge([
        'name' => 'World Para Swimming European Championships 2026',
        'short_name' => 'EM 2026',
        'type' => Championship::TYPE_EC,
        'year' => 2026,
        'qualification_start' => '2025-01-01',
        'qualification_end' => '2026-07-06',
    ], $abweichungen));
}

function standard_wq2(Championship $championship, array $abweichungen): ChampionshipStandard
{
    return ChampionshipStandard::query()->create(array_merge([
        'championship_id' => $championship->getKey(),
        'stroke_type_id' => stroke_wq2('FREE')->id,
        'distance' => 100,
        'gender' => 'M',
        'sport_class' => 'S7',
        'mqs_centiseconds' => 7319,
    ], $abweichungen));
}

/** Gültige WPS-Version mit einem Parametersatz für 100 m Freistil M S7 LCM. */
function pointVersion_wq2(): WpsPointVersion
{
    $version = WpsPointVersion::query()->create([
        'label' => 'WPS 2026',
        'year' => 2026,
        'version' => '1',
        'status' => WpsPointVersion::STATUS_ACTIVE,
        'valid_from' => '2026-01-01',
    ]);

    WpsPointParameter::query()->create([
        'wps_point_version_id' => $version->id,
        'course' => WpsPointParameter::COURSE_LCM,
        'gender' => 'M',
        'stroke_type_id' => stroke_wq2('FREE')->id,
        'distance' => 100,
        'relay_count' => 1,
        'sport_class' => 'S7',
        'parameter_a' => 1200,
        'parameter_b' => 6.19,
        'parameter_c' => 515.385,
    ]);

    return $version;
}

beforeEach(function () {
    $this->service = app(ChampionshipStandardService::class);
    BaseTimeSportClass::query()->firstOrCreate(['code' => 'S7'], ['sort_order' => 7]);
    BaseTimeSportClass::query()->firstOrCreate(['code' => 'S5'], ['sort_order' => 5]);
});

// ── Berechtigungen (§4) ──────────────────────────────────────────────────────

it('lässt angemeldete Nutzer Übersicht und Normtabelle lesen', function () {
    $championship = championship_wq2([]);

    $this->actingAs(clubUser_wq2())->get(route('championships.index'))->assertOk();
    $this->actingAs(clubUser_wq2())->get(route('championships.show', $championship))->assertOk();
});

it('verwehrt Club-Nutzern die Verwaltung', function () {
    $nutzer = clubUser_wq2();
    $championship = championship_wq2([]);
    $quelle = championship_wq2(['name' => 'WM 2027', 'short_name' => 'WM 2027', 'year' => 2027]);

    $this->actingAs($nutzer)->get(route('championships.create'))->assertForbidden();
    $this->actingAs($nutzer)->post(route('championships.store'))->assertForbidden();
    $this->actingAs($nutzer)->get(route('championships.edit', $championship))->assertForbidden();
    $this->actingAs($nutzer)->put(route('championships.update', $championship))->assertForbidden();
    $this->actingAs($nutzer)->delete(route('championships.destroy', $championship))->assertForbidden();
    $this->actingAs($nutzer)
        ->post(route('championships.copy-from', $championship), ['source_id' => $quelle->id])
        ->assertForbidden();
});

it('verwehrt Club-Nutzern das Bearbeiten über die Livewire-Komponente', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, []);

    Livewire::actingAs(clubUser_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'mqs', '01:10.00')
        ->assertForbidden();

    expect($standard->fresh()->mqs_centiseconds)->toBe(7319);
});

// ── CRUD Meisterschaft ───────────────────────────────────────────────────────

it('legt eine Meisterschaft über das Formular an', function () {
    $this->actingAs(admin_wq2())->post(route('championships.store'), [
        'name' => 'Weltmeisterschaft 2027',
        'short_name' => 'WM 2027',
        'type' => Championship::TYPE_WC,
        'year' => 2027,
        'course' => Championship::COURSE_LCM,
        'qualification_start' => '2026-01-01',
        'qualification_end' => '2027-06-30',
        'is_active' => '1',
    ])->assertRedirect();

    $championship = Championship::query()->sole();

    expect($championship->short_name)->toBe('WM 2027')
        ->and($championship->is_active)->toBeTrue();
});

it('weist ein Ende vor dem Beginn des Qualifikationszeitraums ab', function () {
    $this->actingAs(admin_wq2())->post(route('championships.store'), [
        'name' => 'Unsinn',
        'type' => Championship::TYPE_OTHER,
        'year' => 2027,
        'course' => Championship::COURSE_LCM,
        'qualification_start' => '2027-06-30',
        'qualification_end' => '2026-01-01',
    ])->assertSessionHasErrors('qualification_end');

    expect(Championship::query()->count())->toBe(0);
});

it('kann eine Meisterschaft deaktivieren obwohl die Checkbox nicht übertragen wird', function () {
    $championship = championship_wq2([]);

    $this->actingAs(admin_wq2())->put(route('championships.update', $championship), [
        'name' => $championship->name,
        'type' => Championship::TYPE_EC,
        'year' => 2026,
        'course' => Championship::COURSE_LCM,
        'qualification_start' => '2025-01-01',
        'qualification_end' => '2026-07-06',
        // is_active fehlt bewusst — genau das sendet ein Browser bei nicht gesetztem Haken
    ])->assertRedirect();

    expect($championship->fresh()->is_active)->toBeFalse();
});

it('löscht eine Meisterschaft samt Normen', function () {
    $championship = championship_wq2([]);
    standard_wq2($championship, []);

    $this->actingAs(admin_wq2())->delete(route('championships.destroy', $championship))->assertRedirect();

    expect(Championship::query()->count())->toBe(0)
        ->and(ChampionshipStandard::query()->count())->toBe(0);
});

it('zeigt in der Übersicht die Anzahl der Normen und der offenen Zeilen', function () {
    $championship = championship_wq2([]);
    standard_wq2($championship, []);                                   // offen
    standard_wq2($championship, ['distance' => 50, 'obsv_percent' => 0]); // festgelegt

    $this->actingAs(admin_wq2())->get(route('championships.index'))
        ->assertOk()
        ->assertViewHas('championships', fn ($liste) => $liste->first()->standards_count === 2
            && $liste->first()->open_standards_count === 1);
});

// ── Inline-Bearbeitung ───────────────────────────────────────────────────────

it('speichert eine MQS über die Inline-Bearbeitung', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, ['mqs_centiseconds' => null]);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'mqs', '01:13.19')
        ->assertHasNoErrors();

    expect($standard->fresh()->mqs_centiseconds)->toBe(7319);
});

it('weist ein ungültiges Zeitformat ab ohne zu speichern', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, []);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'mqs', 'keine Zeit')
        ->assertHasErrors("rows.$standard->id.mqs");

    expect($standard->fresh()->mqs_centiseconds)->toBe(7319);
});

it('rechnet die ÖBSV-Zeit nach wenn die MQS geändert wird', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, ['obsv_percent' => 2.0, 'obsv_centiseconds' => 7172]);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'mqs', '01:10.00');

    // 7000 × 0,98 = 6860
    expect($standard->fresh()->obsv_centiseconds)->toBe(6860);
});

it('lässt eine von Hand gesetzte ÖBSV-Zeit bei geänderter MQS unangetastet', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, [
        'obsv_percent' => 2.0,
        'obsv_centiseconds' => 7000,
        'obsv_is_manual' => true,
    ]);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'mqs', '01:10.00');

    expect($standard->fresh()->obsv_centiseconds)->toBe(7000);
});

it('setzt beim Eintragen einer ÖBSV-Zeit das Handzeichen', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, ['obsv_percent' => 2.0, 'obsv_centiseconds' => 7172]);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'obsv', '01:09.00');

    expect($standard->fresh()->obsv_centiseconds)->toBe(6900)
        ->and($standard->fresh()->isObsvManual())->toBeTrue()
        ->and($standard->fresh()->obsv_percent)->toBe(2.0);
});

it('setzt eine Zeile mit leerem Prozentfeld wieder auf offen', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, ['obsv_percent' => 2.0, 'obsv_centiseconds' => 7172]);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'percent', '');

    expect($standard->fresh()->isObsvOpen())->toBeTrue()
        ->and($standard->fresh()->obsv_centiseconds)->toBeNull();
});

it('nimmt einen Prozentsatz mit Komma entgegen', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, []);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'percent', '2,5')
        ->assertHasNoErrors();

    // 7319 × 0,975 = 7136,025 → floor → 7136
    expect($standard->fresh()->obsv_percent)->toBe(2.5)
        ->and($standard->fresh()->obsv_centiseconds)->toBe(7136);
});

it('ignoriert einen unbekannten Feldnamen statt ihn zu schreiben', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, []);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('saveCell', $standard->id, 'sport_class', 'S1')
        ->assertHasNoErrors();

    expect($standard->fresh()->sport_class)->toBe('S7');
});

// ── Zeilen anlegen und löschen ───────────────────────────────────────────────

it('legt eine Zeile über das Formular an', function () {
    $championship = championship_wq2([]);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->set('newStrokeTypeId', (string) stroke_wq2('FREE')->id)
        ->set('newDistance', '50')
        ->set('newGender', 'F')
        ->set('newSportClass', 's5')
        ->call('addRow')
        ->assertHasNoErrors();

    $standard = ChampionshipStandard::query()->sole();

    expect($standard->sport_class)->toBe('S5')
        ->and($standard->gender)->toBe('F')
        ->and($standard->isObsvOpen())->toBeTrue();
});

it('weist eine unbekannte Sportklasse beim Anlegen ab', function () {
    $championship = championship_wq2([]);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->set('newStrokeTypeId', (string) stroke_wq2('FREE')->id)
        ->set('newDistance', '50')
        ->set('newSportClass', 'S99')
        ->call('addRow')
        ->assertHasErrors('newSportClass');

    expect(ChampionshipStandard::query()->count())->toBe(0);
});

it('löscht eine Zeile', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, []);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->call('deleteRow', $standard->id);

    expect(ChampionshipStandard::query()->count())->toBe(0);
});

// ── Massenaktion ─────────────────────────────────────────────────────────────

it('wendet die Massenaktion nur auf offene Zeilen an', function () {
    $championship = championship_wq2([]);

    $offen = standard_wq2($championship, []);
    $vonHand = standard_wq2($championship, [
        'distance' => 50,
        'mqs_centiseconds' => 3500,
        'obsv_percent' => 5.0,
        'obsv_centiseconds' => 3300,
        'obsv_is_manual' => true,
    ]);
    $festgelegt = standard_wq2($championship, [
        'distance' => 200,
        'mqs_centiseconds' => 15000,
        'obsv_percent' => 0,
        'obsv_centiseconds' => 15000,
    ]);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->set('bulkPercent', '2')
        ->call('applyBulkPercent')
        ->assertHasNoErrors();

    expect($offen->fresh()->obsv_centiseconds)->toBe(7172)
        ->and($vonHand->fresh()->obsv_centiseconds)->toBe(3300)
        ->and($festgelegt->fresh()->obsv_centiseconds)->toBe(15000);
});

it('wendet die Massenaktion auch auf ausgefilterte Zeilen an', function () {
    $championship = championship_wq2([]);

    $frei = standard_wq2($championship, []);
    $ruecken = standard_wq2($championship, ['stroke_type_id' => stroke_wq2('BACK')->id]);

    Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship])
        ->set('filterStroke', (string) stroke_wq2('FREE')->id)
        ->set('bulkPercent', '2')
        ->call('applyBulkPercent');

    // Ein Filter ist eine Sicht, keine Auswahl — sonst hinge das Ergebnis davon ab,
    // was zufällig im Filterfeld stand.
    expect($frei->fresh()->obsv_centiseconds)->toBe(7172)
        ->and($ruecken->fresh()->obsv_centiseconds)->toBe(7172);
});

// ── Filter ───────────────────────────────────────────────────────────────────

it('filtert die Normtabelle nach Bewerb, Geschlecht und Sportklasse', function () {
    $championship = championship_wq2([]);

    standard_wq2($championship, []);                                    // FREE / M / S7
    standard_wq2($championship, ['gender' => 'F']);                     // FREE / F / S7
    standard_wq2($championship, ['stroke_type_id' => stroke_wq2('BACK')->id]); // BACK / M / S7

    $komponente = Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship]);

    expect($komponente->instance()->standards())->toHaveCount(3);

    $komponente->set('filterGender', 'M');
    expect($komponente->instance()->standards())->toHaveCount(2);

    $komponente->set('filterStroke', (string) stroke_wq2('FREE')->id);
    expect($komponente->instance()->standards())->toHaveCount(1);

    $komponente->call('resetFilters');
    expect($komponente->instance()->standards())->toHaveCount(3);
});

// ── Kopierfunktion (§9.1) ────────────────────────────────────────────────────

it('kopiert MQS und MET, aber keine ÖBSV-Werte', function () {
    $quelle = championship_wq2([]);
    $ziel = championship_wq2(['name' => 'EM 2028', 'short_name' => 'EM 2028', 'year' => 2028]);

    standard_wq2($quelle, [
        'met_centiseconds' => 7800,
        'obsv_percent' => 2.0,
        'obsv_centiseconds' => 7172,
        'obsv_is_manual' => true,
    ]);

    $anzahl = $this->service->copyStandards($quelle, $ziel);
    $kopie = $ziel->standards()->sole();

    expect($anzahl)->toBe(1)
        ->and($kopie->mqs_centiseconds)->toBe(7319)
        ->and($kopie->met_centiseconds)->toBe(7800)
        ->and($kopie->obsv_percent)->toBeNull()
        ->and($kopie->obsv_centiseconds)->toBeNull()
        ->and($kopie->obsv_is_manual)->toBeFalse()
        ->and($kopie->isObsvOpen())->toBeTrue();
});

it('überspringt beim Kopieren bereits vorhandene Zeilen', function () {
    $quelle = championship_wq2([]);
    $ziel = championship_wq2(['name' => 'EM 2028', 'short_name' => 'EM 2028', 'year' => 2028]);

    standard_wq2($quelle, []);
    standard_wq2($quelle, ['distance' => 50, 'mqs_centiseconds' => 3500]);
    $vorhanden = standard_wq2($ziel, ['mqs_centiseconds' => 7000, 'obsv_percent' => 3.0]);

    $anzahl = $this->service->copyStandards($quelle, $ziel);

    expect($anzahl)->toBe(1)
        ->and($ziel->standards()->count())->toBe(2)
        ->and($vorhanden->fresh()->mqs_centiseconds)->toBe(7000)
        ->and($vorhanden->fresh()->obsv_percent)->toBe(3.0);
});

it('kopiert über die Route und ist gefahrlos wiederholbar', function () {
    $quelle = championship_wq2([]);
    $ziel = championship_wq2(['name' => 'EM 2028', 'short_name' => 'EM 2028', 'year' => 2028]);
    standard_wq2($quelle, []);

    $admin = admin_wq2();
    $this->actingAs($admin)
        ->post(route('championships.copy-from', $ziel), ['source_id' => $quelle->id])
        ->assertRedirect();
    $this->actingAs($admin)
        ->post(route('championships.copy-from', $ziel), ['source_id' => $quelle->id])
        ->assertRedirect();

    expect($ziel->standards()->count())->toBe(1);
});

it('weist die eigene Meisterschaft als Kopierquelle ab', function () {
    $championship = championship_wq2([]);

    $this->actingAs(admin_wq2())
        ->post(route('championships.copy-from', $championship), ['source_id' => $championship->id])
        ->assertSessionHasErrors('source_id');
});

// ── Punktanzeige (§5.3, Risiko Q-R6) ─────────────────────────────────────────

it('berechnet Punkte für eine freistehende Normzeit', function () {
    $version = pointVersion_wq2();

    $punkte = app(WpsPointCalculator::class)->pointsForTime(
        7319,
        WpsPointParameter::COURSE_LCM,
        'M',
        stroke_wq2('FREE')->id,
        100,
        'S7',
        $version,
    );

    // q = 1200 × e^(-e^(6,19 − 515,385 / 73,19)), abgerundet
    $erwartet = (int) floor(1200 * exp(-exp(6.19 - 515.385 / 73.19)));

    expect($punkte)->toBe($erwartet)->and($punkte)->toBeGreaterThan(0);
});

it('liefert ohne passenden Parametersatz keine Punkte statt einer Null', function () {
    $version = pointVersion_wq2();

    expect(app(WpsPointCalculator::class)->pointsForTime(
        7319, WpsPointParameter::COURSE_LCM, 'F', stroke_wq2('FREE')->id, 100, 'S7', $version
    ))->toBeNull();
});

it('zeigt in der Normtabelle die Punkte neben MQS und ÖBSV-Zeit', function () {
    pointVersion_wq2();
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, ['obsv_percent' => 2.0, 'obsv_centiseconds' => 7172]);

    $komponente = Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship]);

    $mqsPunkte = $komponente->instance()->pointsFor(7319, $standard);
    $obsvPunkte = $komponente->instance()->pointsFor(7172, $standard);

    // Die schärfere ÖBSV-Norm muss mehr Punkte verlangen als die MQS.
    expect($mqsPunkte)->not->toBeNull()
        ->and($obsvPunkte)->not->toBeNull()
        ->and($obsvPunkte)->toBeGreaterThan($mqsPunkte);
});

it('lässt die Punktspalten leer wenn keine gültige Version hinterlegt ist', function () {
    $championship = championship_wq2([]);
    $standard = standard_wq2($championship, []);

    $komponente = Livewire::actingAs(admin_wq2())
        ->test(ChampionshipStandardTable::class, ['championship' => $championship]);

    expect($komponente->instance()->pointVersion())->toBeNull()
        ->and($komponente->instance()->pointsFor(7319, $standard))->toBeNull();
});

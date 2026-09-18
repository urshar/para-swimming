<?php

use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_qtl10(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_qtl10(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeStrokeType_qtl10(string $lenexCode): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $lenexCode], [
        'name_de' => $lenexCode, 'name_en' => $lenexCode, 'code' => strtolower($lenexCode),
    ]);
}

function makeGroup_qtl10(string $code, int $sortOrder): SportClassGroup
{
    return SportClassGroup::create(['code' => $code, 'name_de' => $code, 'sort_order' => $sortOrder]);
}

function makeQualifyingList_qtl10(): QualifyingTimeList
{
    return QualifyingTimeList::create(['year' => 2027, 'is_active' => true]);
}

function makeQualifyingTime_qtl10(
    QualifyingTimeList $list, StrokeType $stroke, int $distance, string $gender, string $sportClass, int $value
): QualifyingTime {
    return QualifyingTime::create([
        'qualifying_time_list_id' => $list->id, 'stroke_type_id' => $stroke->id, 'distance' => $distance,
        'gender' => $gender, 'sport_class' => $sportClass, 'value_centiseconds' => $value,
        'source' => QualifyingTime::SOURCE_CALCULATED,
    ]);
}

// ── Anzeige (show) ────────────────────────────────────────────────────────────
//
// Seit 17.09.2026 (Erik) Sportklassen-Nummer-zentriert statt Behinderungsgruppen-zentriert:
// eine flache Tabelle je Nummer (S/SB/SM zusammengefasst), mit Lage/Sportklasse als Spalte
// statt als weitere Verschachtelung — schnelleres Finden einer einzelnen Sportklasse über alle
// Lagen hinweg. Die Bearbeiten-Ansicht (edit, siehe unten) behält weiterhin die
// Behinderungsgruppen-Gliederung, das war nicht Teil dieser Änderung.

describe('Richtzeitenliste — Gliederung nach Sportklassen-Nummer (show)', function () {
    it('gliedert die Richtzeiten nach Sportklassen-Nummer, mit der Lage als Tabellenspalte', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');
        $back = makeStrokeType_qtl10('BACK');

        makeQualifyingTime_qtl10($list, $back, 100, 'M', 'S9', 6000);
        makeQualifyingTime_qtl10($list, $free, 50, 'M', 'S9', 3000);
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S9', 6000);
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S12', 6100);

        $response = $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list));

        $response->assertOk();
        $response->assertSeeInOrder(['S9', 'S12']);
        // Innerhalb von S9: 50m Freistil, dann 100m Freistil, dann 100m Rücken (Lage-Spalte)
        $response->assertSeeInOrder(['S9', '50m FREE', '100m FREE', '100m BACK', 'S12']);
    })->group('qualifying-time-lists-grouping');

    it('fasst S/SB/SM mit derselben Nummer in einem gemeinsamen Abschnitt zusammen', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');
        $breast = makeStrokeType_qtl10('BREAST');
        $medley = makeStrokeType_qtl10('MEDLEY');

        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S14', 6000);
        makeQualifyingTime_qtl10($list, $breast, 100, 'M', 'SB14', 7000);
        makeQualifyingTime_qtl10($list, $medley, 200, 'M', 'SM14', 15000);

        $response = $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list));

        // Nur ein Akkordeon-Abschnitt (id="number-14") für alle drei Präfixe — die
        // Sportklassenwerte SB14/SM14 tauchen zwar weiterhin als Zellinhalt auf, aber
        // nicht als eigene Abschnitts-Id.
        $response->assertOk()
            ->assertSee('id="number-14"', false)
            ->assertSee('100m FREE')
            ->assertSee('100m BREAST')
            ->assertSee('200m MEDLEY')
            ->assertDontSee('id="number-sb14"', false)
            ->assertDontSee('id="number-sm14"', false);

        expect(substr_count($response->getContent(), 'id="number-14"'))->toBe(1);
    })->group('qualifying-time-lists-grouping');

    it('zeigt Sportklassen mit unerwartetem Format unter „Sonstige Sportklassen"', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');
        // "T9" passt nicht auf das S/SB/SM-Präfix-Format und hat daher keine erkennbare Nummer.
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'T9', 6000);

        $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list))
            ->assertOk()
            ->assertSee('Sonstige Sportklassen')
            ->assertSee('T9');
    })->group('qualifying-time-lists-grouping');

    it('sortiert die Abschnitte numerisch (S9 vor S10, nicht alphabetisch)', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');

        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S10', 6000);
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S1', 6000);
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S9', 6000);

        $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list))
            ->assertOk()
            ->assertSeeInOrder(['S1', 'S9', 'S10']);
    })->group('qualifying-time-lists-grouping');
});

// ── Filterleiste auf der Anzeige-Seite (Erik, 17.09.2026; Dropdown statt Buttons und kein
//    Akkordeon mehr seit dem Design-Feedback vom selben Tag — siehe DisabilityGroupGrouper und
//    qualifying-times-show-filter.js) ──────────────────────────────────────────────────────

describe('Richtzeitenliste — Filterleiste nach Geschlecht und Sportklassen-Nummer (show)', function () {
    it('bietet nur tatsächlich vorkommende Geschlechter als Buttons und alle Sportklassen-Nummern im Dropdown an', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');

        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S9', 6000);

        $response = $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list));

        $response->assertOk()
            ->assertSee('x-data="qualifyingTimesShowFilter()"', false)
            ->assertSee('x-on:click="gender = \'M\'"', false)
            ->assertSee('x-model="selectedNumber"', false)
            ->assertSee('wire:key="9"', false)
            // Nur ein Geschlecht in den Testdaten — kein Filterbutton fuer ein
            // nicht vorkommendes Geschlecht.
            ->assertDontSee('x-on:click="gender = \'F\'"', false);
    })->group('qualifying-time-lists-grouping');

    it('fasst S9/SB9/SM9 unter derselben Nummer-Option zusammen statt je eigener Option', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');
        $breast = makeStrokeType_qtl10('BREAST');

        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S9', 6000);
        makeQualifyingTime_qtl10($list, $breast, 100, 'M', 'SB9', 7000);

        $response = $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list));

        // Genau eine Dropdown-Option für Nummer 9 — nicht je eine für S9 und SB9.
        expect(substr_count($response->getContent(), 'wire:key="9"'))->toBe(1);
    })->group('qualifying-time-lists-grouping');

    it('blendet Zeilen und Abschnitte deklarativ per x-show nach Geschlecht bzw. Sportklassen-Nummer aus', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');

        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S9', 6000);

        $response = $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list));

        $response->assertOk()
            ->assertSee('x-show="gender === \'\' || gender === \'M\'"', false)
            ->assertSee('x-show="selectedNumber === \'ALL\' || selectedNumber === \'9\'"', false);
    })->group('qualifying-time-lists-grouping');
});

// ── Bearbeiten-Ansicht (edit) ─────────────────────────────────────────────────────

describe('Richtzeitenliste — Gliederung in der Bearbeiten-Ansicht (edit)', function () {
    it('zeigt dieselbe Gliederung inklusive Lösch-Buttons pro Zeile', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');
        $group = makeGroup_qtl10('PI', 1);
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);

        $time = makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S9', 6000);

        $response = $this->actingAs(makeAdmin_qtl10())
            ->get(route('qualifying-time-lists.edit', $list));

        $response->assertOk()
            ->assertSee('PI')
            ->assertSee('100m FREE')
            ->assertSee(route('qualifying-time-lists.times.destroy', [$list, $time]), false);
    })->group('qualifying-time-lists-grouping');
});

// ── Inhaltsverzeichnis / Sprungmarken (Erik, 2026-07-20; Anker seit 17.09.2026 nach
//    Sportklassen-Nummer statt Behinderungsgruppe) ─────────────────────────────────

describe('Inhaltsverzeichnis auf den Anzeige-Seiten', function () {
    it('zeigt auf der Richtzeiten-Anzeige einen Sprunglink pro Sportklassen-Nummer', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S9', 6000);

        $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list))
            ->assertOk()
            ->assertSee('Inhaltsverzeichnis')
            ->assertSee('href="#number-9"', false);
    })->group('qualifying-time-lists-grouping');
});

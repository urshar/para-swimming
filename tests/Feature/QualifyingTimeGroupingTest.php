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

describe('Richtzeitenliste — Gliederung nach Behinderungsgruppe und Lage (show)', function () {
    it('gliedert die Richtzeiten zuerst nach Behinderungsgruppe, dann nach Bewerb', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');
        $back = makeStrokeType_qtl10('BACK');
        $groupPI = makeGroup_qtl10('PI', 1);
        $groupVI = makeGroup_qtl10('VI', 2);
        SportClassGroupMember::create(['sport_class_group_id' => $groupPI->id, 'sport_class' => 'S9']);
        SportClassGroupMember::create(['sport_class_group_id' => $groupVI->id, 'sport_class' => 'S12']);

        makeQualifyingTime_qtl10($list, $back, 100, 'M', 'S9', 6000);
        makeQualifyingTime_qtl10($list, $free, 50, 'M', 'S9', 3000);
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S9', 6000);
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S12', 6100);

        $response = $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list));

        $response->assertOk();
        $response->assertSeeInOrder(['PI', 'VI']);
        // Innerhalb von PI: 50m Freistil, dann 100m Freistil, dann 100m Rücken
        $response->assertSeeInOrder(['PI', '50m FREE', '100m FREE', '100m BACK', 'VI']);
    })->group('qualifying-time-lists-grouping');

    it('zeigt nicht zugeordnete Sportklassen unter „Sonstige Sportklassen"', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');
        // Bewusst keine SportClassGroupMember für S99
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S99', 6000);

        $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list))
            ->assertOk()
            ->assertSee('Sonstige Sportklassen')
            ->assertSee('S99');
    })->group('qualifying-time-lists-grouping');

    it('sortiert Sportklassen innerhalb eines Bewerbs natürlich (S10 nach S9, nicht nach S1)', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');
        $group = makeGroup_qtl10('PI', 1);
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S1']);
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S10']);

        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S10', 6000);
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S1', 6000);
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S9', 6000);

        $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list))
            ->assertOk()
            ->assertSeeInOrder(['S1', 'S9', 'S10']);
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

// ── Inhaltsverzeichnis / Sprungmarken (Erik, 2026-07-20) ─────────────────────────

describe('Inhaltsverzeichnis auf den Anzeige-Seiten', function () {
    it('zeigt auf der Richtzeiten-Anzeige einen Sprunglink pro Behinderungsgruppe', function () {
        $list = makeQualifyingList_qtl10();
        $free = makeStrokeType_qtl10('FREE');
        $group = makeGroup_qtl10('PI', 1);
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);
        makeQualifyingTime_qtl10($list, $free, 100, 'M', 'S9', 6000);

        $this->actingAs(makeClubUser_qtl10())
            ->get(route('qualifying-time-lists.show', $list))
            ->assertOk()
            ->assertSee('Inhaltsverzeichnis')
            ->assertSee('href="#group-'.$group->id.'"', false);
    })->group('qualifying-time-lists-grouping');
});

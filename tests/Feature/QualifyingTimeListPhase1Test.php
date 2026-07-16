<?php

use App\Models\BaseTimeSportClass;
use App\Models\QualifyingTargetPoint;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\StrokeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_qtl1(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_qtl1(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeStrokeType_qtl1(string $code = 'FREE'): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $code], [
        'name_de' => $code,
        'name_en' => $code,
        'code' => strtolower($code),
    ]);
}

function makeBaseTimeSportClass_qtl1(string $code = 'S9'): BaseTimeSportClass
{
    return BaseTimeSportClass::firstOrCreate(['code' => $code], ['sort_order' => 0]);
}

function makeList_qtl1(int $year = 2026): QualifyingTimeList
{
    return QualifyingTimeList::create(['year' => $year, 'is_active' => true]);
}

// ── QualifyingTimeListController: Liste ─────────────────────────────────────

describe('QualifyingTimeListController — Liste', function () {
    it('Club-User bekommt 403 auf create/store/edit/update/destroy', function () {
        $list = makeList_qtl1();

        $this->actingAs(makeClubUser_qtl1())->get(route('qualifying-time-lists.create'))->assertForbidden();
        $this->actingAs(makeClubUser_qtl1())->post(route('qualifying-time-lists.store'), ['year' => 2027])
            ->assertForbidden();
        $this->actingAs(makeClubUser_qtl1())->get(route('qualifying-time-lists.edit', $list))->assertForbidden();
        $this->actingAs(makeClubUser_qtl1())->put(route('qualifying-time-lists.update', $list), ['year' => 2026])
            ->assertForbidden();
        $this->actingAs(makeClubUser_qtl1())->delete(route('qualifying-time-lists.destroy', $list))
            ->assertForbidden();
    })->group('qualifying-time-lists-p1');

    it('Club-User kann index und show lesen', function () {
        $list = makeList_qtl1();

        $this->actingAs(makeClubUser_qtl1())->get(route('qualifying-time-lists.index'))->assertOk();
        $this->actingAs(makeClubUser_qtl1())->get(route('qualifying-time-lists.show', $list))->assertOk();
    })->group('qualifying-time-lists-p1');

    it('Admin kann eine Richtzeitenliste anlegen', function () {
        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.store'), ['year' => 2026, 'is_active' => 1])
            ->assertRedirect();

        expect(QualifyingTimeList::where('year', 2026)->exists())->toBeTrue();
    })->group('qualifying-time-lists-p1');

    it('verhindert doppelte Wettkampfjahre', function () {
        makeList_qtl1(2026);

        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.store'), ['year' => 2026, 'is_active' => 1])
            ->assertSessionHasErrors('year');

        expect(QualifyingTimeList::where('year', 2026)->count())->toBe(1);
    })->group('qualifying-time-lists-p1');

    it('Admin kann eine Richtzeitenliste aktualisieren', function () {
        $list = makeList_qtl1();

        $this->actingAs(makeAdmin_qtl1())
            ->put(route('qualifying-time-lists.update', $list), ['year' => $list->year, 'is_active' => 0])
            ->assertRedirect();

        expect($list->fresh()->is_active)->toBeFalse();
    })->group('qualifying-time-lists-p1');

    it('Admin kann eine Richtzeitenliste löschen, kaskadiert Zielpunkte und Richtzeiten', function () {
        $list = makeList_qtl1();
        makeBaseTimeSportClass_qtl1('S9');
        QualifyingTargetPoint::create(['qualifying_time_list_id' => $list->id, 'sport_class' => 'S9', 'points' => 100]);
        QualifyingTime::create([
            'qualifying_time_list_id' => $list->id,
            'stroke_type_id' => makeStrokeType_qtl1()->id,
            'distance' => 100,
            'gender' => 'M',
            'sport_class' => 'S9',
        ]);

        $this->actingAs(makeAdmin_qtl1())
            ->delete(route('qualifying-time-lists.destroy', $list))
            ->assertRedirect(route('qualifying-time-lists.index'));

        expect(QualifyingTimeList::find($list->id))->toBeNull()
            ->and(QualifyingTargetPoint::count())->toBe(0)
            ->and(QualifyingTime::count())->toBe(0);
    })->group('qualifying-time-lists-p1');
});

// ── Zielpunkte ───────────────────────────────────────────────────────────────

describe('QualifyingTimeListController — Zielpunkte', function () {
    it('Admin kann einen Zielpunkte-Override anlegen', function () {
        $list = makeList_qtl1();
        makeBaseTimeSportClass_qtl1('S2');

        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.target-points.store', $list), [
                'sport_class' => 'S2',
                'points' => 110,
            ])
            ->assertRedirect();

        expect(QualifyingTargetPoint::where('sport_class', 'S2')->first()?->points)->toBe(110);
    })->group('qualifying-time-lists-p1');

    it('S2 und SB2 können unterschiedliche Zielpunkte haben', function () {
        $list = makeList_qtl1();
        makeBaseTimeSportClass_qtl1('S2');

        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.target-points.store', $list), ['sport_class' => 'S2', 'points' => 110]);
        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.target-points.store', $list), ['sport_class' => 'SB2', 'points' => 120]);

        expect($list->fresh()->targetPointsFor('S2'))->toBe(110)
            ->and($list->fresh()->targetPointsFor('SB2'))->toBe(120)
            ->and($list->fresh()->targetPointsFor('SM2'))->toBe(100); // Default, kein Override
    })->group('qualifying-time-lists-p1');

    it('lehnt eine Sportklasse ab, deren Zahl nicht in base_time_sport_classes existiert', function () {
        $list = makeList_qtl1();
        // Bewusst KEIN BaseTimeSportClass für S99 angelegt

        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.target-points.store', $list), [
                'sport_class' => 'S99',
                'points' => 100,
            ])
            ->assertSessionHasErrors('sport_class');

        expect(QualifyingTargetPoint::count())->toBe(0);
    })->group('qualifying-time-lists-p1');

    it('lehnt ein ungültiges Sportklassen-Format ab', function () {
        $list = makeList_qtl1();

        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.target-points.store', $list), [
                'sport_class' => 'X9',
                'points' => 100,
            ])
            ->assertSessionHasErrors('sport_class');
    })->group('qualifying-time-lists-p1');

    it('Admin kann einen Zielpunkte-Override löschen', function () {
        $list = makeList_qtl1();
        makeBaseTimeSportClass_qtl1('S2');
        $tp = QualifyingTargetPoint::create([
            'qualifying_time_list_id' => $list->id,
            'sport_class' => 'S2',
            'points' => 110,
        ]);

        $this->actingAs(makeAdmin_qtl1())
            ->delete(route('qualifying-time-lists.target-points.destroy', [$list, $tp]))
            ->assertRedirect();

        expect(QualifyingTargetPoint::find($tp->id))->toBeNull();
    })->group('qualifying-time-lists-p1');

    it('Club-User bekommt 403 beim Anlegen von Zielpunkten', function () {
        $list = makeList_qtl1();
        makeBaseTimeSportClass_qtl1('S2');

        $this->actingAs(makeClubUser_qtl1())
            ->post(route('qualifying-time-lists.target-points.store', $list), ['sport_class' => 'S2', 'points' => 110])
            ->assertForbidden();
    })->group('qualifying-time-lists-p1');
});

// ── Richtzeiten-Zeilen ────────────────────────────────────────────────────────

describe('QualifyingTimeListController — Richtzeiten-Zeilen', function () {
    it('Admin kann eine Richtzeit anlegen', function () {
        $list = makeList_qtl1();
        $stroke = makeStrokeType_qtl1();
        makeBaseTimeSportClass_qtl1('S9');

        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.times.store', $list), [
                'stroke_type_id' => $stroke->id,
                'distance' => 100,
                'gender' => 'M',
                'sport_class' => 'S9',
                'value' => '01:23.45',
            ])
            ->assertRedirect();

        $time = QualifyingTime::where('qualifying_time_list_id', $list->id)->first();
        expect($time)->not->toBeNull()
            ->and($time->value_centiseconds)->toBe(8345)
            ->and($time->formatted_value)->toBe('01:23.45');
    })->group('qualifying-time-lists-p1');

    it('lehnt ein ungültiges Zeitformat ab', function () {
        $list = makeList_qtl1();
        $stroke = makeStrokeType_qtl1();
        makeBaseTimeSportClass_qtl1('S9');

        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.times.store', $list), [
                'stroke_type_id' => $stroke->id,
                'distance' => 100,
                'gender' => 'M',
                'sport_class' => 'S9',
                'value' => 'keine-zeit',
            ])
            ->assertSessionHasErrors('value');

        expect(QualifyingTime::count())->toBe(0);
    })->group('qualifying-time-lists-p1');

    it('verhindert doppelte Zeilen (gleicher Bewerb+Geschlecht+Sportklasse) — upsert statt Duplikat', function () {
        $list = makeList_qtl1();
        $stroke = makeStrokeType_qtl1();
        makeBaseTimeSportClass_qtl1('S9');

        $payload = [
            'stroke_type_id' => $stroke->id,
            'distance' => 100,
            'gender' => 'M',
            'sport_class' => 'S9',
        ];

        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.times.store', $list), [...$payload, 'value' => '01:23.45']);
        $this->actingAs(makeAdmin_qtl1())
            ->post(route('qualifying-time-lists.times.store', $list), [...$payload, 'value' => '01:20.00']);

        expect(QualifyingTime::where('qualifying_time_list_id', $list->id)->count())->toBe(1)
            ->and(QualifyingTime::first()->formatted_value)->toBe('01:20.00');
    })->group('qualifying-time-lists-p1');

    it('Admin kann eine Richtzeit löschen', function () {
        $list = makeList_qtl1();
        $stroke = makeStrokeType_qtl1();
        $time = QualifyingTime::create([
            'qualifying_time_list_id' => $list->id,
            'stroke_type_id' => $stroke->id,
            'distance' => 100,
            'gender' => 'M',
            'sport_class' => 'S9',
        ]);

        $this->actingAs(makeAdmin_qtl1())
            ->delete(route('qualifying-time-lists.times.destroy', [$list, $time]))
            ->assertRedirect();

        expect(QualifyingTime::find($time->id))->toBeNull();
    })->group('qualifying-time-lists-p1');

    it('Club-User bekommt 403 beim Anlegen von Richtzeiten', function () {
        $list = makeList_qtl1();
        $stroke = makeStrokeType_qtl1();
        makeBaseTimeSportClass_qtl1('S9');

        $this->actingAs(makeClubUser_qtl1())
            ->post(route('qualifying-time-lists.times.store', $list), [
                'stroke_type_id' => $stroke->id,
                'distance' => 100,
                'gender' => 'M',
                'sport_class' => 'S9',
                'value' => '01:23.45',
            ])
            ->assertForbidden();
    })->group('qualifying-time-lists-p1');
});

// ── Meet-Zuordnung (Vorbereitung Phase 4) ────────────────────────────────────

describe('Meet::qualifyingTimeList', function () {
    it('ein Meet kann optional einer Richtzeitenliste zugeordnet werden', function () {
        $list = makeList_qtl1();
        $meet = App\Models\Meet::create([
            'name' => 'ÖSTM & ÖM 2026',
            'nation_id' => App\Models\Nation::create([
                'code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
            ])->id,
            'course' => 'LCM',
            'start_date' => '2026-05-12',
            'qualifying_time_list_id' => $list->id,
        ]);

        expect($meet->fresh()->qualifyingTimeList->year)->toBe(2026);
    })->group('qualifying-time-lists-p1');
});

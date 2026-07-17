<?php

use App\Models\QualifyingTargetPoint;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\StrokeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_qtl5(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeList_qtl5(int $year): QualifyingTimeList
{
    return QualifyingTimeList::create(['year' => $year, 'is_active' => true]);
}

function makeStrokeType_qtl5(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'], [
        'name_de' => 'FREE', 'name_en' => 'FREE', 'code' => 'free',
    ]);
}

// ── QualifyingTimeList::isLatest() ──────────────────────────────────────────────

describe('QualifyingTimeList::isLatest()', function () {
    it('ist true für das höchste Jahr, false für ältere Jahre', function () {
        $list2026 = makeList_qtl5(2026);
        $list2027 = makeList_qtl5(2027);

        expect($list2026->isLatest())->toBeFalse()
            ->and($list2027->isLatest())->toBeTrue();
    })->group('qualifying-time-lists-p3');

    it('ist true, wenn nur eine Liste existiert', function () {
        $list = makeList_qtl5(2026);

        expect($list->isLatest())->toBeTrue();
    })->group('qualifying-time-lists-p3');
});

// ── Schreibschutz historisierter Listen ──────────────────────────────────────────

describe('QualifyingTimeListController — Historisierung', function () {
    it('Admin bekommt 403 beim Bearbeiten einer historisierten (nicht-aktuellsten) Liste', function () {
        $old = makeList_qtl5(2026);
        makeList_qtl5(2027);

        $this->actingAs(makeAdmin_qtl5())->get(route('qualifying-time-lists.edit', $old))->assertForbidden();
        $this->actingAs(makeAdmin_qtl5())
            ->put(route('qualifying-time-lists.update', $old), ['year' => 2026, 'is_active' => 0])
            ->assertForbidden();
        $this->actingAs(makeAdmin_qtl5())->delete(route('qualifying-time-lists.destroy', $old))->assertForbidden();
    })->group('qualifying-time-lists-p3');

    it('Admin bekommt 403 beim Pflegen von Zielpunkten/Richtzeiten einer historisierten Liste', function () {
        $old = makeList_qtl5(2026);
        makeList_qtl5(2027);
        $stroke = makeStrokeType_qtl5();

        $this->actingAs(makeAdmin_qtl5())
            ->post(route('qualifying-time-lists.target-points.store', $old), ['sport_class' => 'S9', 'points' => 100])
            ->assertForbidden();
        $this->actingAs(makeAdmin_qtl5())
            ->post(route('qualifying-time-lists.times.store', $old), [
                'stroke_type_id' => $stroke->id, 'distance' => 100, 'gender' => 'M',
                'sport_class' => 'S9', 'value' => '01:00.00',
            ])
            ->assertForbidden();
        $this->actingAs(makeAdmin_qtl5())
            ->post(route('qualifying-time-lists.calculate', $old))
            ->assertForbidden();
    })->group('qualifying-time-lists-p3');

    it('Admin kann die aktuellste Liste weiterhin normal bearbeiten', function () {
        makeList_qtl5(2026);
        $current = makeList_qtl5(2027);

        $this->actingAs(makeAdmin_qtl5())->get(route('qualifying-time-lists.edit', $current))->assertOk();
        $this->actingAs(makeAdmin_qtl5())
            ->post(route('qualifying-time-lists.target-points.store', $current), [
                'sport_class' => 'S9', 'points' => 100,
            ])
            ->assertRedirect();
    })->group('qualifying-time-lists-p3');

    it('eine ältere Liste bleibt über show() weiterhin lesbar', function () {
        $old = makeList_qtl5(2026);
        makeList_qtl5(2027);

        $this->actingAs(makeAdmin_qtl5())->get(route('qualifying-time-lists.show', $old))->assertOk();
    })->group('qualifying-time-lists-p3');

    it('eine neue Liste anzulegen bleibt für Admins immer möglich', function () {
        makeList_qtl5(2026);

        $this->actingAs(makeAdmin_qtl5())
            ->post(route('qualifying-time-lists.store'), ['year' => 2027, 'is_active' => 1])
            ->assertRedirect();

        expect(QualifyingTimeList::where('year', 2027)->exists())->toBeTrue();
    })->group('qualifying-time-lists-p3');
});

// ── Isolation zwischen Jahren ────────────────────────────────────────────────────

describe('Isolation zwischen Wettkampfjahren', function () {
    it('Löschen einer Liste hat keine Auswirkung auf andere Jahre', function () {
        $list2026 = makeList_qtl5(2026);
        $list2027 = makeList_qtl5(2027);
        QualifyingTargetPoint::create(['qualifying_time_list_id' => $list2026->id, 'sport_class' => 'S9', 'points' => 100]);
        QualifyingTargetPoint::create(['qualifying_time_list_id' => $list2027->id, 'sport_class' => 'S9', 'points' => 100]);

        // 2027 ist aktuell -> löschbar
        $this->actingAs(makeAdmin_qtl5())->delete(route('qualifying-time-lists.destroy', $list2027));

        expect(QualifyingTimeList::find($list2026->id))->not->toBeNull()
            ->and(QualifyingTargetPoint::where('qualifying_time_list_id', $list2026->id)->count())->toBe(1);
    })->group('qualifying-time-lists-p3');

    it('Zeilen unterschiedlicher Jahre sind über die FK strikt getrennt', function () {
        $list2026 = makeList_qtl5(2026);
        $list2027 = makeList_qtl5(2027);
        $stroke = makeStrokeType_qtl5();

        QualifyingTime::create([
            'qualifying_time_list_id' => $list2026->id, 'stroke_type_id' => $stroke->id,
            'distance' => 100, 'gender' => 'M', 'sport_class' => 'S9', 'value_centiseconds' => 6000,
        ]);
        QualifyingTime::create([
            'qualifying_time_list_id' => $list2027->id, 'stroke_type_id' => $stroke->id,
            'distance' => 100, 'gender' => 'M', 'sport_class' => 'S9', 'value_centiseconds' => 5900,
        ]);

        expect($list2026->fresh()->times)->toHaveCount(1)
            ->and($list2026->fresh()->times->first()->value_centiseconds)->toBe(6000)
            ->and($list2027->fresh()->times->first()->value_centiseconds)->toBe(5900);
    })->group('qualifying-time-lists-p3');
});

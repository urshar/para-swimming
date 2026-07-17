<?php

use App\Models\BaseTimeVersion;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\QualifyingTimeList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_qtl3(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_qtl3(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_qtl3(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeQualifyingTimeList_qtl3(int $year = 2026): QualifyingTimeList
{
    return QualifyingTimeList::create(['year' => $year, 'is_active' => true]);
}

function meetPayload_qtl3(array $overrides = []): array
{
    return array_merge([
        'name' => 'Testmeet', 'city' => 'Wien', 'nation_id' => makeNation_qtl3()->id,
        'course' => 'LCM', 'start_date' => '2026-06-01',
    ], $overrides);
}

// ── store ──────────────────────────────────────────────────────────────────────

describe('store', function () {
    it('Admin kann ein Meet direkt einer Richtzeitenliste zuordnen', function () {
        $list = makeQualifyingTimeList_qtl3();

        $this->actingAs(makeAdmin_qtl3())
            ->post(route('meets.store'), meetPayload_qtl3(['qualifying_time_list_id' => $list->id]))
            ->assertRedirect();

        expect(Meet::where('name', 'Testmeet')->firstOrFail()->qualifying_time_list_id)->toBe($list->id);
    })->group('qualifying-time-lists-p2');

    it('qualifying_time_list_id eines Club-Users wird ignoriert', function () {
        $list = makeQualifyingTimeList_qtl3();

        $this->actingAs(makeClubUser_qtl3())
            ->post(route('meets.store'), meetPayload_qtl3(['qualifying_time_list_id' => $list->id]))
            ->assertRedirect();

        expect(Meet::where('name', 'Testmeet')->firstOrFail()->qualifying_time_list_id)->toBeNull();
    })->group('qualifying-time-lists-p2');
});

// ── update ─────────────────────────────────────────────────────────────────────

describe('update', function () {
    it('Admin kann die Richtzeitenlisten-Zuordnung eines bestehenden Meets ändern', function () {
        $meet = Meet::create(meetPayload_qtl3());
        $list = makeQualifyingTimeList_qtl3();

        $this->actingAs(makeAdmin_qtl3())
            ->put(route('meets.update', $meet), meetPayload_qtl3(['qualifying_time_list_id' => $list->id]))
            ->assertRedirect();

        expect($meet->fresh()->qualifying_time_list_id)->toBe($list->id);
    })->group('qualifying-time-lists-p2');

    it('Admin kann die Zuordnung wieder entfernen', function () {
        $list = makeQualifyingTimeList_qtl3();
        $meet = Meet::create(meetPayload_qtl3(['qualifying_time_list_id' => $list->id]));

        $this->actingAs(makeAdmin_qtl3())
            ->put(route('meets.update', $meet), meetPayload_qtl3(['qualifying_time_list_id' => '']))
            ->assertRedirect();

        expect($meet->fresh()->qualifying_time_list_id)->toBeNull();
    })->group('qualifying-time-lists-p2');

    it('Änderung eines Club-Users wird ignoriert', function () {
        $meet = Meet::create(meetPayload_qtl3());
        $list = makeQualifyingTimeList_qtl3();

        $this->actingAs(makeClubUser_qtl3())
            ->put(route('meets.update', $meet), meetPayload_qtl3(['qualifying_time_list_id' => $list->id]))
            ->assertRedirect();

        expect($meet->fresh()->qualifying_time_list_id)->toBeNull();
    })->group('qualifying-time-lists-p2');
});

// ── Zusammenspiel mit calculate() ──────────────────────────────────────────────

describe('calculate() nach Zuordnung', function () {
    it('funktioniert erst, nachdem ein Meet der Liste zugeordnet wurde', function () {
        $list = makeQualifyingTimeList_qtl3();

        $this->actingAs(makeAdmin_qtl3())
            ->post(route('qualifying-time-lists.calculate', $list))
            ->assertSessionHas('error');

        Meet::create(meetPayload_qtl3(['qualifying_time_list_id' => $list->id]));
        BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

        $this->actingAs(makeAdmin_qtl3())
            ->post(route('qualifying-time-lists.calculate', $list))
            ->assertSessionMissing('error');
    })->group('qualifying-time-lists-p2');
});

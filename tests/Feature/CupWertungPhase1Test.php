<?php

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\AthleteKaderMembership;
use App\Models\BaseTimeVersion;
use App\Models\Cup;
use App\Models\KaderType;
use App\Models\Nation;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_cup1(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_cup1(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeBaseTimeVersion_cup1(): BaseTimeVersion
{
    return BaseTimeVersion::create([
        'label' => '2021–2026',
        'valid_from' => '2021-01-01',
        'valid_until' => null,
    ]);
}

function makeNation_cup1(string $code = 'AUT'): Nation
{
    return Nation::create(['code' => $code, 'name_de' => $code, 'name_en' => $code, 'is_active' => true]);
}

function makeAthlete_cup1(array $attrs = []): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'gender' => 'M',
        'nation_id' => makeNation_cup1()->id,
        'is_active' => true,
    ], $attrs));
}

// ── CupController ────────────────────────────────────────────────────────────

describe('CupController', function () {
    it('Club-User bekommt 403 auf allen Aktionen', function () {
        $this->actingAs(makeClubUser_cup1())->get(route('cups.index'))->assertForbidden();
        $this->actingAs(makeClubUser_cup1())->get(route('cups.create'))->assertForbidden();
    })->group('cup-wertung-p1');

    it('Admin kann einen Cup anlegen', function () {
        $version = makeBaseTimeVersion_cup1();

        $this->actingAs(makeAdmin_cup1())
            ->post(route('cups.store'), [
                'year' => 2026,
                'name' => 'ÖBSV Cup 2026',
                'base_time_version_id' => $version->id,
                'rounds_count' => 4,
                'best_of_count' => 3,
                'top_group_points_threshold' => 450,
                'is_active' => 1,
            ])
            ->assertRedirect(route('cups.index'));

        expect(Cup::where('year', 2026)->exists())->toBeTrue();
    })->group('cup-wertung-p1');

    it('verhindert doppelte Cup-Jahre', function () {
        $version = makeBaseTimeVersion_cup1();
        Cup::create([
            'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
            'rounds_count' => 1, 'best_of_count' => 1, 'top_group_points_threshold' => 450,
        ]);

        $this->actingAs(makeAdmin_cup1())
            ->post(route('cups.store'), [
                'year' => 2026,
                'name' => 'Doppelter Cup',
                'base_time_version_id' => $version->id,
                'rounds_count' => 1,
                'best_of_count' => 1,
                'top_group_points_threshold' => 450,
            ])
            ->assertSessionHasErrors('year');
    })->group('cup-wertung-p1');

    it('speichert aktive Sportklassengruppen beim Anlegen', function () {
        $version = makeBaseTimeVersion_cup1();
        $groupA = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_virtual' => false, 'is_active' => true]);
        $groupB = SportClassGroup::create(['code' => 'VI', 'name_de' => 'VI', 'is_virtual' => false, 'is_active' => true]);

        $this->actingAs(makeAdmin_cup1())
            ->post(route('cups.store'), [
                'year' => 2027,
                'name' => 'ÖBSV Cup 2027',
                'base_time_version_id' => $version->id,
                'rounds_count' => 1,
                'best_of_count' => 1,
                'top_group_points_threshold' => 450,
                'active_group_ids' => [$groupA->id],
            ]);

        $cup = Cup::where('year', 2027)->firstOrFail();

        expect($cup->isGroupActive($groupA))->toBeTrue()
            ->and($cup->isGroupActive($groupB))->toBeFalse();
    })->group('cup-wertung-p1');

    it('löscht einen Cup', function () {
        $version = makeBaseTimeVersion_cup1();
        $cup = Cup::create([
            'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
            'rounds_count' => 1, 'best_of_count' => 1, 'top_group_points_threshold' => 450,
        ]);

        $this->actingAs(makeAdmin_cup1())
            ->delete(route('cups.destroy', $cup))
            ->assertRedirect(route('cups.index'));

        expect(Cup::find($cup->id))->toBeNull();
    })->group('cup-wertung-p1');
});

// ── KaderTypeController ──────────────────────────────────────────────────────

describe('KaderTypeController', function () {
    it('Club-User bekommt 403', function () {
        $this->actingAs(makeClubUser_cup1())->get(route('kader-types.index'))->assertForbidden();
    })->group('cup-wertung-p1');

    it('Admin kann eine Kaderart anlegen', function () {
        $this->actingAs(makeAdmin_cup1())
            ->post(route('kader-types.store'), [
                'code' => 'WELTKLASSE', 'name_de' => 'Weltklasse', 'is_active' => 1,
            ])
            ->assertRedirect(route('kader-types.index'));

        expect(KaderType::where('code', 'WELTKLASSE')->exists())->toBeTrue();
    })->group('cup-wertung-p1');

    it('verhindert das Löschen einer Kaderart mit zugeordneten Athleten', function () {
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse', 'is_active' => true]);
        $athlete = makeAthlete_cup1();
        AthleteKaderMembership::create(['athlete_id' => $athlete->id, 'kader_type_id' => $kaderType->id]);

        $this->actingAs(makeAdmin_cup1())
            ->delete(route('kader-types.destroy', $kaderType))
            ->assertRedirect(route('kader-types.index'));

        expect(KaderType::find($kaderType->id))->not->toBeNull();
    })->group('cup-wertung-p1');
});

// ── AgeGroupController ───────────────────────────────────────────────────────

describe('AgeGroupController', function () {
    it('Club-User bekommt 403', function () {
        $this->actingAs(makeClubUser_cup1())->get(route('age-groups.index'))->assertForbidden();
    })->group('cup-wertung-p1');

    it('Admin kann eine Altersgruppe anlegen', function () {
        $this->actingAs(makeAdmin_cup1())
            ->post(route('age-groups.store'), [
                'code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => 1,
            ])
            ->assertRedirect(route('age-groups.index'));

        expect(AgeGroup::where('code', 'JUGEND')->exists())->toBeTrue();
    })->group('cup-wertung-p1');

    it('lehnt max_age kleiner min_age ab', function () {
        $this->actingAs(makeAdmin_cup1())
            ->post(route('age-groups.store'), [
                'code' => 'INVALID', 'name_de' => 'Invalid', 'min_age' => 20, 'max_age' => 10,
            ])
            ->assertSessionHasErrors('max_age');
    })->group('cup-wertung-p1');
});

// ── SportClassGroupController ────────────────────────────────────────────────

describe('SportClassGroupController', function () {
    it('Club-User bekommt 403', function () {
        $this->actingAs(makeClubUser_cup1())->get(route('sport-class-groups.index'))->assertForbidden();
    })->group('cup-wertung-p1');

    it('Admin kann eine Gruppe anlegen und Sportklassen zuordnen', function () {
        $admin = makeAdmin_cup1();

        $this->actingAs($admin)
            ->post(route('sport-class-groups.store'), [
                'code' => 'PI', 'name_de' => 'Körperliche Behinderung', 'is_active' => 1,
            ])
            ->assertRedirect();

        $group = SportClassGroup::where('code', 'PI')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('sport-class-groups.members.store', $group), [
                'sport_classes' => 'S1, S2, S3',
            ])
            ->assertRedirect(route('sport-class-groups.edit', $group));

        expect($group->members()->pluck('sport_class')->all())->toBe(['S1', 'S2', 'S3']);
    })->group('cup-wertung-p1');

    it('überspringt Sportklassen, die bereits einer anderen Gruppe zugeordnet sind', function () {
        $admin = makeAdmin_cup1();
        $groupA = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $groupB = SportClassGroup::create(['code' => 'VI', 'name_de' => 'VI', 'is_active' => true]);
        SportClassGroupMember::create(['sport_class_group_id' => $groupA->id, 'sport_class' => 'S1']);

        $this->actingAs($admin)
            ->post(route('sport-class-groups.members.store', $groupB), ['sport_classes' => 'S1, S11']);

        expect($groupA->members()->pluck('sport_class')->all())->toBe(['S1'])
            ->and($groupB->members()->pluck('sport_class')->all())->toBe(['S11']);
    })->group('cup-wertung-p1');

    it('entfernt eine Sportklasse aus einer Gruppe', function () {
        $admin = makeAdmin_cup1();
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $member = SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S1']);

        $this->actingAs($admin)
            ->delete(route('sport-class-groups.members.destroy', [$group, $member]))
            ->assertRedirect(route('sport-class-groups.edit', $group));

        expect(SportClassGroupMember::find($member->id))->toBeNull();
    })->group('cup-wertung-p1');
});

// ── Athlete Kader-Zugehörigkeit ──────────────────────────────────────────────

describe('Athlete Kaderzugehörigkeit', function () {
    it('Club-User bekommt 403', function () {
        $athlete = makeAthlete_cup1();
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse', 'is_active' => true]);

        $this->actingAs(makeClubUser_cup1())
            ->post(route('athletes.kader-memberships.store', $athlete), ['kader_type_id' => $kaderType->id])
            ->assertForbidden();
    })->group('cup-wertung-p1');

    it('Admin kann eine Kaderzugehörigkeit eintragen und löschen', function () {
        $admin = makeAdmin_cup1();
        $athlete = makeAthlete_cup1();
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('athletes.kader-memberships.store', $athlete), [
                'kader_type_id' => $kaderType->id,
                'valid_from' => '2026-01-01',
            ])
            ->assertRedirect(route('athletes.show', $athlete));

        $membership = AthleteKaderMembership::where('athlete_id', $athlete->id)->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('athletes.kader-memberships.destroy', [$athlete, $membership]))
            ->assertRedirect(route('athletes.show', $athlete));

        expect(AthleteKaderMembership::find($membership->id))->toBeNull();
    })->group('cup-wertung-p1');
});

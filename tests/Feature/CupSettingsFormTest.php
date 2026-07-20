<?php

use App\Models\AgeGroup;
use App\Models\BaseTimeVersion;
use App\Models\Cup;
use App\Models\CupGroupSetting;
use App\Models\SportClassGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_cup13(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeBaseTimeVersion_cup13(): BaseTimeVersion
{
    return BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);
}

function cupPayload_cup13(array $overrides = []): array
{
    return array_merge([
        'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => makeBaseTimeVersion_cup13()->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ], $overrides);
}

describe('store', function () {
    it('speichert gender_combined_group_ids und active_age_group_ids (Matrix pro Sportklassengruppe) korrekt', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $jugend = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);
        $offen = AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'is_active' => true]);

        $this->actingAs(makeAdmin_cup13())
            ->post(route('cups.store'), cupPayload_cup13([
                'active_group_ids' => [$group->id],
                'gender_combined_group_ids' => [$group->id],
                'active_age_group_ids' => [$group->id => [$offen->id]], // Jugend NICHT angehakt für PI
            ]))
            ->assertRedirect(route('cups.index'));

        $cup = Cup::where('year', 2026)->firstOrFail();

        expect(CupGroupSetting::where('cup_id', $cup->id)->where('sport_class_group_id', $group->id)->first()->gender_combined)
            ->toBeTrue()
            ->and($cup->isAgeGroupActive($jugend, $group))->toBeFalse()
            ->and($cup->isAgeGroupActive($offen, $group))->toBeTrue();
    })->group('cup-wertung-p12');

    it('setzt gender_combined auf false, wenn keine Gruppe angehakt wurde', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);

        $this->actingAs(makeAdmin_cup13())
            ->post(route('cups.store'), cupPayload_cup13(['active_group_ids' => [$group->id]]));

        $cup = Cup::where('year', 2026)->firstOrFail();

        expect($cup->isGenderCombined($group))->toBeFalse();
    })->group('cup-wertung-p12');

    it('erlaubt unterschiedliche Altersgruppen-Aktivierung je Sportklassengruppe', function () {
        $groupPI = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $groupVI = SportClassGroup::create(['code' => 'VI', 'name_de' => 'VI', 'is_active' => true]);
        $jugend = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);

        $this->actingAs(makeAdmin_cup13())
            ->post(route('cups.store'), cupPayload_cup13([
                'active_group_ids' => [$groupPI->id, $groupVI->id],
                'active_age_group_ids' => [$groupVI->id => [$jugend->id]], // Jugend nur für VI angehakt
            ]))
            ->assertRedirect(route('cups.index'));

        $cup = Cup::where('year', 2026)->firstOrFail();

        expect($cup->isAgeGroupActive($jugend, $groupPI))->toBeFalse()
            ->and($cup->isAgeGroupActive($jugend, $groupVI))->toBeTrue();
    })->group('cup-wertung-p12');
});

describe('edit', function () {
    it('zeigt die zuvor gespeicherten Einstellungen vorausgewählt an', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $jugend = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);
        $version = makeBaseTimeVersion_cup13();
        $cup = Cup::create([
            'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
            'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
        ]);
        $cup->groupSettings()->create(['sport_class_group_id' => $group->id, 'is_active' => true, 'gender_combined' => true]);
        $cup->ageGroupSettings()->create([
            'sport_class_group_id' => $group->id, 'age_group_id' => $jugend->id, 'is_active' => false,
        ]);

        $this->actingAs(makeAdmin_cup13())
            ->get(route('cups.edit', $cup))
            ->assertOk()
            ->assertSee('Damen & Herren gemeinsam')
            ->assertSee('Jugend')
            ->assertSee('PI');
    })->group('cup-wertung-p12');
});

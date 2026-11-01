<?php

use App\Models\Meet;
use App\Models\PointSystem;
use App\Models\User;
use App\Models\WpsPointVersion;
use App\Services\WpsVersionImpactService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('wps-version-edit-p1');

// Die Helper (result_wps2, version_wps2, …) stammen aus tests/helpers_wps2.php.

function admin_wve1(): User
{
    return User::factory()->create(['is_admin' => true]);
}

function enableWps_wve1(Meet $meet, ?WpsPointVersion $version = null): void
{
    $system = PointSystem::firstOrCreate(
        ['code' => PointSystem::CODE_WPS],
        ['name' => 'World Para Swimming Points']
    );

    $meet->pointSystems()->syncWithoutDetaching([
        $system->id => ['wps_point_version_id' => $version?->id],
    ]);
}

/** @param array<string, mixed> $overrides */
function input_wve1(array $overrides = []): array
{
    return array_merge([
        'label' => 'WPS 2026 korrigiert',
        'year' => 2026,
        'version' => '2',
        'source' => 'World Para Swimming',
        'valid_from' => '2026-02-01',
        'valid_until' => null,
    ], $overrides);
}

function impact_wve1(): WpsVersionImpactService
{
    return app(WpsVersionImpactService::class);
}

// ── Formular und Speichern ───────────────────────────────────────────────────

describe('Version bearbeiten', function () {
    it('zeigt das Formular mit den bisherigen Daten', function () {
        $version = version_wps2();

        $this->actingAs(admin_wve1())
            ->get(route('wps.versions.edit', $version))
            ->assertOk()
            ->assertSee('Version bearbeiten')
            ->assertSee('value="WPS 2026"', false)
            ->assertSee('2026-01-01');
    });

    it('verlinkt das Formular aus Liste und Detailseite', function () {
        $version = version_wps2();
        $admin = admin_wve1();

        $this->actingAs($admin)
            ->get(route('wps.versions.index'))
            ->assertSee(route('wps.versions.edit', $version), false);

        $this->actingAs($admin)
            ->get(route('wps.versions.show', $version))
            ->assertSee(route('wps.versions.edit', $version), false);
    });

    it('speichert die allgemeinen Daten', function () {
        $version = version_wps2();

        $this->actingAs(admin_wve1())
            ->put(route('wps.versions.update', $version), input_wve1(['valid_until' => '2026-12-31']))
            ->assertRedirect(route('wps.versions.show', $version))
            ->assertSessionHas('success');

        $version->refresh();

        expect($version->label)->toBe('WPS 2026 korrigiert')
            ->and($version->version)->toBe('2')
            ->and($version->source)->toBe('World Para Swimming')
            ->and($version->valid_from->format('Y-m-d'))->toBe('2026-02-01')
            ->and($version->valid_until->format('Y-m-d'))->toBe('2026-12-31');
    });

    it('leert Version, Quelle und Gültig bis', function () {
        $version = version_wps2();
        $version->update(['source' => 'alt', 'valid_until' => '2026-12-31']);

        $this->actingAs(admin_wve1())
            ->put(route('wps.versions.update', $version), input_wve1([
                'version' => '',
                'source' => '',
                'valid_until' => '',
            ]))
            ->assertRedirect(route('wps.versions.show', $version))
            ->assertSessionHas('success');

        $version->refresh();

        expect($version->version)->toBeNull()
            ->and($version->source)->toBeNull()
            ->and($version->valid_until)->toBeNull();
    });

    it('verlangt Bezeichnung, Jahr und Gültig ab', function () {
        $version = version_wps2();

        $this->actingAs(admin_wve1())
            ->put(route('wps.versions.update', $version), input_wve1([
                'label' => '',
                'year' => '',
                'valid_from' => '',
            ]))
            ->assertSessionHasErrors(['label', 'year', 'valid_from']);

        expect($version->fresh()->label)->toBe('WPS 2026');
    });

    it('lehnt ein Gültig bis vor dem Gültig ab ab', function () {
        $version = version_wps2();

        $this->actingAs(admin_wve1())
            ->put(route('wps.versions.update', $version), input_wve1([
                'valid_from' => '2026-06-01',
                'valid_until' => '2026-05-01',
            ]))
            ->assertSessionHasErrors('valid_until');
    });

    it('lehnt eine bereits vorhandene Kombination aus Jahr und Version ab', function () {
        $version = version_wps2();
        WpsPointVersion::create(['label' => 'WPS 2026 v2', 'year' => 2026, 'version' => '2']);

        $this->actingAs(admin_wve1())
            ->put(route('wps.versions.update', $version), input_wve1(['version' => '2']))
            ->assertSessionHasErrors('version');

        expect($version->fresh()->version)->toBe('1');
    });

    it('erlaubt Jahr und Version der Version selbst unverändert', function () {
        $version = version_wps2();

        $this->actingAs(admin_wve1())
            ->put(route('wps.versions.update', $version), input_wve1(['version' => '1']))
            ->assertRedirect(route('wps.versions.show', $version))
            ->assertSessionHas('success');
    });

    it('ist ohne WPS-Schreibrecht nicht erreichbar', function () {
        $version = version_wps2();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('wps.versions.edit', $version))
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('wps.versions.update', $version), input_wve1())
            ->assertForbidden();

        expect($version->fresh()->label)->toBe('WPS 2026');
    });
});

// ── Hinweis auf betroffene Wettkämpfe ────────────────────────────────────────

describe('Betroffene Wettkämpfe', function () {
    it('meldet nichts, solange die gespeicherte Version der gewählten entspricht', function () {
        $version = version_wps2();
        $result = result_wps2(['wps_point_version_id' => $version->id, 'wps_points' => 500]);
        enableWps_wve1($result->meet);

        expect(impact_wve1()->affectedMeets($version))->toBeEmpty();
    });

    it('meldet einen Wettkampf, der nach dem neuen Gültig ab keine Version mehr hat', function () {
        $version = version_wps2();
        $result = result_wps2(['wps_point_version_id' => $version->id, 'wps_points' => 500]);
        enableWps_wve1($result->meet);

        // Wettkampf am 01.05.2026, neue Gültigkeit erst ab 01.06.2026.
        $this->actingAs(admin_wve1())
            ->put(route('wps.versions.update', $version), input_wve1(['valid_from' => '2026-06-01']));

        $affected = impact_wve1()->affectedMeets($version->fresh());

        expect($affected)->toHaveCount(1)
            ->and($affected->first()['meet']->is($result->meet))->toBeTrue()
            ->and($affected->first()['resolved'])->toBeNull();

        $this->actingAs(admin_wve1())
            ->get(route('wps.versions.show', $version))
            ->assertSee('würden heute mit einer anderen Version gerechnet')
            ->assertSee(route('meets.show', $result->meet), false)
            ->assertSee('keine gültige Version');
    });

    it('meldet einen Wettkampf, für den jetzt eine andere Version gilt', function () {
        $old = version_wps2();
        $new = WpsPointVersion::create([
            'label' => 'WPS 2026 Korrektur',
            'year' => 2026,
            'version' => '2',
            'valid_from' => '2026-07-01',
        ]);
        $result = result_wps2(['wps_point_version_id' => $old->id, 'wps_points' => 500]);
        enableWps_wve1($result->meet);

        // Die Korrektur gilt nachträglich schon ab April und überdeckt damit den Wettkampf vom 01.05.
        $new->update(['valid_from' => '2026-04-01']);

        expect(impact_wve1()->affectedMeets($new))->toHaveCount(1)
            ->and(impact_wve1()->affectedMeets($old))->toHaveCount(1)
            ->and(impact_wve1()->affectedMeets($new)->first()['resolved']->is($new))->toBeTrue();
    });

    it('übergeht Wettkämpfe mit ausdrücklich zugewiesener Version', function () {
        $version = version_wps2();
        $result = result_wps2(['wps_point_version_id' => $version->id, 'wps_points' => 500]);
        enableWps_wve1($result->meet, $version);

        $version->update(['valid_from' => '2026-06-01']);

        expect(impact_wve1()->affectedMeets($version))->toBeEmpty();
    });

    it('übergeht Wettkämpfe, die eine andere Version betreffen', function () {
        $version = version_wps2();
        $other = WpsPointVersion::create([
            'label' => 'WPS 2017',
            'year' => 2017,
            'version' => '1',
            'valid_from' => '2017-01-01',
            'valid_until' => '2017-12-31',
        ]);
        $result = result_wps2(['wps_point_version_id' => $version->id, 'wps_points' => 500]);
        enableWps_wve1($result->meet);

        $version->update(['valid_from' => '2026-06-01']);

        expect(impact_wve1()->affectedMeets($other))->toBeEmpty();
    });
});

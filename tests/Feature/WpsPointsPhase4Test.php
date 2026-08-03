<?php

use App\Jobs\CalculateWpsPointsJob;
use App\Models\Club;
use App\Models\Meet;
use App\Models\PointSystem;
use App\Models\Result;
use App\Models\User;
use App\Models\WpsPointVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class)->group('wps-points-p4');

// Die Helper (result_wps2, parameter_wps2, version_wps2, …) stammen aus tests/helpers_wps2.php.

function wpsSystem_wps4(): PointSystem
{
    return PointSystem::firstOrCreate(
        ['code' => PointSystem::CODE_WPS],
        ['name' => 'World Para Swimming Points']
    );
}

function waSystem_wps4(): PointSystem
{
    return PointSystem::firstOrCreate(
        ['code' => PointSystem::CODE_WORLD_AQUATICS],
        ['name' => 'World Aquatics Punkte']
    );
}

function enableWps_wps4(Meet $meet, ?WpsPointVersion $version = null): void
{
    $meet->pointSystems()->syncWithoutDetaching([
        wpsSystem_wps4()->id => ['wps_point_version_id' => $version?->id],
    ]);
}

function adminUser_wps4(): User
{
    return User::factory()->create(['is_admin' => true]);
}

/** Vereins-User ohne Adminrechte, aber mit Zugriff auf Meldungen des Wettkampfs. */
function clubUser_wps4(): User
{
    return User::factory()->create([
        'is_admin' => false,
        'club_id' => Club::firstOrCreate(['name' => 'Testverein'], [
            'short_name' => 'TV',
            'nation_id' => nation_wps2()->id,
        ])->id,
    ]);
}

// ── Auslösen der Berechnung ──────────────────────────────────────────────────

describe('Berechnung auslösen', function () {
    it('berechnet die Punkte eines Wettkampfs', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        $result = result_wps2(['swim_time' => 5700, 'sport_class' => 'S2']);
        enableWps_wps4($result->meet);

        $this->actingAs(adminUser_wps4())
            ->post(route('meets.wps-points.recalculate', $result->meet))
            ->assertRedirect()
            ->assertSessionHas('success');

        expect($result->fresh()->wps_points)->toBe(939);
    });

    it('zeigt die Fehlermeldung auf der Wettkampfseite an', function () {
        $result = result_wps2();
        enableWps_wps4($result->meet);

        // Keine gültige Version → der Controller meldet über withErrors('wps') zurück.
        $this->actingAs(adminUser_wps4())
            ->post(route('meets.wps-points.recalculate', $result->meet))
            ->assertSessionHasErrors('wps');

        $this->actingAs(adminUser_wps4())
            ->get(route('meets.show', $result->meet))
            ->assertOk()
            ->assertSee('keine gültige WPS-Version', false);
    });

    it('weist ab, wenn WPS für den Wettkampf nicht aktiviert ist', function () {
        parameter_wps2();
        $result = result_wps2();

        $this->actingAs(adminUser_wps4())
            ->post(route('meets.wps-points.recalculate', $result->meet))
            ->assertSessionHasErrors('wps');

        expect($result->fresh()->wps_points)->toBeNull();
    });

    it('weist ab, wenn für das Wettkampfdatum keine Version gilt', function () {
        $result = result_wps2();
        enableWps_wps4($result->meet);

        $this->actingAs(adminUser_wps4())
            ->post(route('meets.wps-points.recalculate', $result->meet))
            ->assertSessionHasErrors('wps');
    });

    it('nennt übersprungene Ergebnisse mit Grund', function () {
        parameter_wps2();
        $result = result_wps2(['status' => 'DSQ']);
        enableWps_wps4($result->meet);

        $this->actingAs(adminUser_wps4())
            ->post(route('meets.wps-points.recalculate', $result->meet))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'übersprungen')
                && str_contains($message, 'DSQ'));
    });

    it('verwendet eine ausdrücklich gewählte Version', function () {
        parameter_wps2();
        $result = result_wps2();
        enableWps_wps4($result->meet);

        $andere = WpsPointVersion::create([
            'label' => 'WPS 2020', 'year' => 2020, 'version' => '1', 'valid_from' => '2020-01-01',
        ]);

        $this->actingAs(adminUser_wps4())->post(
            route('meets.wps-points.recalculate', $result->meet),
            ['wps_point_version_id' => $andere->id]
        )->assertRedirect();

        // Die gewählte Version kennt keine Parameter — das Ergebnis bleibt ohne Punkte.
        expect($result->fresh()->wps_points)->toBeNull();
    });

    it('lässt bestehende Werte mit only_missing unangetastet', function () {
        parameter_wps2();
        $result = result_wps2();
        $result->update(['wps_points' => 1]);
        enableWps_wps4($result->meet);

        $this->actingAs(adminUser_wps4())->post(
            route('meets.wps-points.recalculate', $result->meet),
            ['only_missing' => '1']
        )->assertRedirect();

        expect($result->fresh()->wps_points)->toBe(1);
    });

    it('lässt results.points unberührt', function () {
        parameter_wps2();
        $result = result_wps2();
        $result->update(['points' => 700]);
        enableWps_wps4($result->meet);

        $this->actingAs(adminUser_wps4())
            ->post(route('meets.wps-points.recalculate', $result->meet));

        expect($result->fresh()->points)->toBe(700);
    });
});

// ── Hintergrundverarbeitung ──────────────────────────────────────────────────

describe('Hintergrundverarbeitung', function () {
    it('rechnet kleine Wettkämpfe synchron', function () {
        Queue::fake();

        parameter_wps2();
        $result = result_wps2();
        enableWps_wps4($result->meet);

        $this->actingAs(adminUser_wps4())
            ->post(route('meets.wps-points.recalculate', $result->meet));

        Queue::assertNothingPushed();
        expect($result->fresh()->wps_points)->not->toBeNull();
    });

    it('übergibt große Wettkämpfe an die Queue', function () {
        Queue::fake();
        config(['wps.sync_threshold' => 0]);

        parameter_wps2();
        $result = result_wps2();
        enableWps_wps4($result->meet);

        $this->actingAs(adminUser_wps4())
            ->post(route('meets.wps-points.recalculate', $result->meet))
            ->assertSessionHas('success');

        Queue::assertPushed(CalculateWpsPointsJob::class);
        expect($result->fresh()->wps_points)->toBeNull();
    });

    it('berechnet die Punkte, wenn der Job läuft', function () {
        parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
        $result = result_wps2(['swim_time' => 5700, 'sport_class' => 'S2']);
        enableWps_wps4($result->meet);

        (new CalculateWpsPointsJob($result->meet->id))
            ->handle(app(App\Services\WpsPointCalculationService::class));

        expect($result->fresh()->wps_points)->toBe(939);
    });

    it('läuft ins Leere, wenn der Wettkampf nicht mehr existiert', function () {
        $job = new CalculateWpsPointsJob(999999);

        $job->handle(app(App\Services\WpsPointCalculationService::class));

        expect(Result::whereNotNull('wps_points')->count())->toBe(0);
    });
});

// ── Berechtigungen ───────────────────────────────────────────────────────────

describe('Berechtigungen', function () {
    it('verwehrt Benutzern ohne Vereinszuordnung den Zugriff', function () {
        parameter_wps2();
        $result = result_wps2();
        enableWps_wps4($result->meet);

        $this->actingAs(User::factory()->create(['is_admin' => false, 'club_id' => null]))
            ->post(route('meets.wps-points.recalculate', $result->meet))
            ->assertForbidden();

        expect($result->fresh()->wps_points)->toBeNull();
    });

    it('lässt Vereins-User mit Meldungsrecht durch', function () {
        parameter_wps2();
        $result = result_wps2();
        enableWps_wps4($result->meet);

        $this->actingAs(clubUser_wps4())
            ->post(route('meets.wps-points.recalculate', $result->meet))
            ->assertRedirect();
    });

    it('leitet nicht angemeldete Besucher zur Anmeldung', function () {
        $result = result_wps2();

        $this->post(route('meets.wps-points.recalculate', $result->meet))
            ->assertRedirect(route('login'));
    });
});

// ── Wettkampf-Formular ───────────────────────────────────────────────────────

describe('Zuordnung im Wettkampf-Formular', function () {
    it('speichert die gewählten Punktesysteme', function () {
        $meet = result_wps2()->meet;
        $version = version_wps2();

        $this->actingAs(adminUser_wps4())->put(route('meets.update', $meet), [
            'name' => $meet->name,
            'city' => 'Wien',
            'nation_id' => $meet->nation_id,
            'course' => 'LCM',
            'start_date' => '2026-05-01',
            'point_systems' => [wpsSystem_wps4()->id, waSystem_wps4()->id],
            'wps_point_version_id' => $version->id,
        ])->assertRedirect();

        $meet = $meet->fresh();

        expect($meet->hasWpsPointsEnabled())->toBeTrue()
            ->and($meet->pointSystems)->toHaveCount(2)
            ->and($meet->pointSystems->firstWhere('code', PointSystem::CODE_WPS)
                ->getRelation('pivot')->getAttribute('wps_point_version_id'))->toBe($version->id);
    });

    it('zeigt eine gespeicherte Zuordnung im Formular wieder angehakt', function () {
        $meet = result_wps2()->meet;
        enableWps_wps4($meet);

        $this->actingAs(adminUser_wps4())
            ->get(route('meets.edit', $meet))
            ->assertOk()
            ->assertViewHas('selectedPointSystems',
                fn (array $selected): bool => in_array(wpsSystem_wps4()->id, $selected, true))
            ->assertViewHas('wpsSystemId', wpsSystem_wps4()->id);
    });

    it('hinterlegt die Version nur beim Punktesystem WPS', function () {
        $meet = result_wps2()->meet;
        $version = version_wps2();

        $this->actingAs(adminUser_wps4())->put(route('meets.update', $meet), [
            'name' => $meet->name,
            'city' => 'Wien',
            'nation_id' => $meet->nation_id,
            'course' => 'LCM',
            'start_date' => '2026-05-01',
            'point_systems' => [waSystem_wps4()->id],
            'wps_point_version_id' => $version->id,
        ]);

        expect($meet->fresh()->pointSystems->first()
            ->getRelation('pivot')->getAttribute('wps_point_version_id'))->toBeNull();
    });

    it('entfernt eine abgewählte Zuordnung', function () {
        $meet = result_wps2()->meet;
        enableWps_wps4($meet);

        $this->actingAs(adminUser_wps4())->put(route('meets.update', $meet), [
            'name' => $meet->name,
            'city' => 'Wien',
            'nation_id' => $meet->nation_id,
            'course' => 'LCM',
            'start_date' => '2026-05-01',
        ]);

        expect($meet->fresh()->hasWpsPointsEnabled())->toBeFalse();
    });

    it('lässt die Zuordnung durch Nicht-Admins unverändert', function () {
        $meet = result_wps2()->meet;
        enableWps_wps4($meet);

        $this->actingAs(clubUser_wps4())->put(route('meets.update', $meet), [
            'name' => $meet->name,
            'city' => 'Wien',
            'nation_id' => $meet->nation_id,
            'course' => 'LCM',
            'start_date' => '2026-05-01',
        ]);

        expect($meet->fresh()->hasWpsPointsEnabled())->toBeTrue();
    });
});

// ── Anzeige ──────────────────────────────────────────────────────────────────

describe('Anzeige', function () {
    it('zeigt WPS-Punkte, Version und Kennzeichnung im Ergebnis', function () {
        $parameter = parameter_wps2();
        $result = result_wps2();
        enableWps_wps4($result->meet);
        app(App\Services\WpsPointCalculationService::class)->recalculateForResult($result);

        $this->actingAs(adminUser_wps4())
            ->get(route('results.show', $result))
            ->assertOk()
            ->assertSee('WPS-Punkte')
            ->assertSee('offiziell')
            ->assertSee(version_wps2()->label);

        expect($parameter->official)->toBeTrue();
    });

    it('kennzeichnet abgeleitete Kurzbahnpunkte als geschätzt', function () {
        parameter_wps2(['course' => 'SCM', 'official' => false]);
        $result = result_wps2(course: 'SCM');
        enableWps_wps4($result->meet);
        app(App\Services\WpsPointCalculationService::class)->recalculateForResult($result);

        $this->actingAs(adminUser_wps4())
            ->get(route('results.show', $result))
            ->assertOk()
            ->assertSee('geschätzt');
    });

    it('blendet den WPS-Block ohne berechnete Punkte aus', function () {
        $result = result_wps2();

        $this->actingAs(adminUser_wps4())
            ->get(route('results.show', $result))
            ->assertOk()
            ->assertDontSee('WPS-Punkte');
    });

    it('zeigt die Schaltfläche nur bei aktiviertem Punktesystem', function () {
        $meet = result_wps2()->meet;
        $admin = adminUser_wps4();

        $this->actingAs($admin)->get(route('meets.show', $meet))
            ->assertOk()
            ->assertDontSee('WPS-Punkte berechnen');

        enableWps_wps4($meet);

        $this->actingAs($admin)->get(route('meets.show', $meet->fresh()))
            ->assertOk()
            ->assertSee('WPS-Punkte berechnen');
    });
});

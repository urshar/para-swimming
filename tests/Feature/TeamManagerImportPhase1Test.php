<?php

use App\Models\Athlete;
use App\Models\AthleteClassification;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('team-manager-import-p1');

// Datenmodell für die Übernahme aus dem Splash Team Manager: Behinderungsgruppe, medizinische Kontrolle,
// Klassifizierung ohne Datum.

function admin_tmi1(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function nation_tmi1(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function athlete_tmi1(array $overrides = []): Athlete
{
    return Athlete::create(array_merge([
        'nation_id' => nation_tmi1()->id,
        'first_name' => 'Carina',
        'last_name' => 'Muster',
        'gender' => 'F',
        'is_active' => true,
    ], $overrides));
}

/** Pflichtfelder des Athleten-Formulars. */
function payload_tmi1(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Carina',
        'last_name' => 'Muster',
        'gender' => 'F',
        'nation_id' => nation_tmi1()->id,
        'is_active' => '1',
    ], $overrides);
}

it('speichert Behinderungsgruppe, PI-Untergruppe und die Termine der medizinischen Kontrolle', function () {
    $this->actingAs(admin_tmi1())
        ->post(route('athletes.store'), payload_tmi1([
            'disability_group' => 'PI',
            'disability_subgroup' => 'R',
            'last_medical_check_at' => '2025-03-14',
            'next_medical_check_at' => '2027-03-14',
        ]))
        ->assertRedirect();

    $athlete = Athlete::firstOrFail();

    expect($athlete->disability_group)->toBe('PI')
        ->and($athlete->disability_subgroup)->toBe('R')
        ->and($athlete->last_medical_check_at->toDateString())->toBe('2025-03-14')
        ->and($athlete->next_medical_check_at->toDateString())->toBe('2027-03-14')
        ->and($athlete->disability_group_label)->toBe('PI – Körperliche Beeinträchtigung (Rollstuhl)');
});

it('lässt eine Untergruppe nur bei körperlicher Beeinträchtigung zu', function () {
    $this->actingAs(admin_tmi1())
        ->post(route('athletes.store'), payload_tmi1(['disability_group' => 'MI', 'disability_subgroup' => 'A']))
        ->assertSessionHasErrors('disability_subgroup');

    $this->actingAs(admin_tmi1())
        ->post(route('athletes.store'), payload_tmi1(['disability_group' => 'XX']))
        ->assertSessionHasErrors('disability_group');

    expect(Athlete::count())->toBe(0);
});

it('zeigt Behinderungsgruppe und medizinische Kontrolle auf der Detailseite', function () {
    $athlete = athlete_tmi1([
        'disability_group' => 'T21',
        'last_medical_check_at' => '2025-03-14',
    ]);

    $this->actingAs(admin_tmi1())
        ->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee('T21 – Down-Syndrom (Trisomie 21)')
        ->assertSee('zuletzt 14.03.2025');
});

it('zeigt und bearbeitet eine Klassifizierung ohne Datum', function () {
    $athlete = athlete_tmi1();
    $classification = AthleteClassification::create([
        'athlete_id' => $athlete->id,
        'classified_at' => null,
        'location' => 'Rif',
        'classification_scope' => 'NAT',
        'classification_status' => 'CONFIRMED',
    ]);

    $this->actingAs(admin_tmi1())
        ->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee('ohne Datum');

    $this->actingAs(admin_tmi1())
        ->put(route('athletes.classifications.update', [$athlete, $classification]), [
            'classified_at' => '',
            'location' => 'Wien',
            'classification_scope' => 'NAT',
            'classification_status' => 'CONFIRMED',
        ])
        ->assertRedirect(route('athletes.show', $athlete));

    expect($classification->fresh()->location)->toBe('Wien')
        ->and($classification->fresh()->classified_at)->toBeNull();
});

<?php

use App\Models\Athlete;
use App\Models\AthleteClassification;
use App\Models\Classifier;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('classifier-show');

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function makeAdmin_cs(): User
{
    return User::forceCreate(['name' => 'Admin', 'email' => 'admin@test.at', 'password' => bcrypt('x'), 'is_admin' => true]);
}

function makeNation_cs(): Nation
{
    return Nation::create(['code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('zeigt Status und Sportklassen-Ergebnis einer Klassifikation lesbar an', function () {
    $classifier = Classifier::forceCreate(['first_name' => 'Max', 'last_name' => 'Muster', 'is_active' => true, 'type' => 'MED']);
    $athlete = Athlete::create([
        'nation_id' => makeNation_cs()->id,
        'first_name' => 'Erika',
        'last_name' => 'Muster',
        'gender' => 'F',
        'is_active' => true,
    ]);
    AthleteClassification::forceCreate([
        'athlete_id' => $athlete->id,
        'med_classifier_id' => $classifier->id,
        'classified_at' => now(),
        'classification_status' => 'CONFIRMED',
        'result_s' => 'S4',
    ]);

    $response = $this->actingAs(makeAdmin_cs())->get(route('classifiers.show', $classifier));

    // classification_status/sport_class_results_display statt der nicht existierenden
    // Felder status/sport_class_result (Model-Accessor-Namen, siehe AthleteClassification).
    $response->assertOk()
        ->assertSee('Confirmed')
        ->assertSee('S4')
        ->assertDontSee('CONFIRMED');
});

it('zählt die Einsätze je Rolle in der Kachel "Einsätze"', function () {
    $classifier = Classifier::forceCreate(['first_name' => 'Stefan', 'last_name' => 'Ernhofer', 'is_active' => true, 'type' => 'MED']);
    $nation = makeNation_cs();

    foreach (['med_classifier_id', 'med_classifier_id', 'tech1_classifier_id'] as $index => $rolle) {
        $athlete = Athlete::create([
            'nation_id' => $nation->id,
            'first_name' => 'Athlet',
            'last_name' => 'Nummer'.$index,
            'gender' => 'F',
            'is_active' => true,
        ]);
        AthleteClassification::forceCreate([
            'athlete_id' => $athlete->id,
            $rolle => $classifier->id,
            'classified_at' => now(),
        ]);
    }

    $this->actingAs(makeAdmin_cs())
        ->get(route('classifiers.show', $classifier))
        ->assertOk()
        ->assertViewHas('classifier', fn (Classifier $c) => $c->classifications_as_med_count === 2
            && $c->classifications_as_tech1_count === 1
            && $c->classifications_as_tech2_count === 0);
});

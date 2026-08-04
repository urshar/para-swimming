<?php

/*
|--------------------------------------------------------------------------
| Testhelfer der WPS Points Engine
|--------------------------------------------------------------------------
|
| Ausgelagert nach dem Vorbild von tests/helpers_p5.php, damit die Helfer in
| allen Testdateien des Moduls zur Verfügung stehen — auch wenn eine einzelne
| Datei isoliert ausgeführt wird. Eingebunden über tests/Pest.php.
|
*/

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\WpsPointParameter;
use App\Models\WpsPointVersion;
use App\Services\WpsPointCalculator;

function nation_wps2(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], [
        'name_de' => 'Österreich',
        'name_en' => 'Austria',
    ]);
}

function stroke_wps2(string $code): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $code], [
        'code' => $code,
        'name_de' => $code,
        'name_en' => $code,
    ]);
}

function version_wps2(): WpsPointVersion
{
    return WpsPointVersion::firstOrCreate(
        ['year' => 2026, 'version' => '1'],
        ['label' => 'WPS 2026', 'valid_from' => '2026-01-01']
    );
}

/** @param array<string, mixed> $overrides */
function parameter_wps2(array $overrides = []): WpsPointParameter
{
    return WpsPointParameter::create(array_merge([
        'wps_point_version_id' => version_wps2()->id,
        'course' => WpsPointParameter::COURSE_LCM,
        'gender' => WpsPointParameter::GENDER_MALE,
        'stroke_type_id' => stroke_wps2('FREE')->id,
        'distance' => 50,
        'relay_count' => 1,
        'sport_class' => 'S10',
        'parameter_a' => 1200,
        'parameter_b' => 6.190278,
        'parameter_c' => 188.441,
        'official' => true,
    ], $overrides));
}

/**
 * Baut ein Ergebnis samt Meet, Bewerb und Athlet.
 *
 * @param  array<string, mixed>  $result
 * @param  array<string, mixed>  $event
 */
function result_wps2(
    array $result = [],
    array $event = [],
    string $course = 'LCM',
    string $athleteGender = 'M',
): Result {
    $nation = nation_wps2();

    $club = Club::firstOrCreate(['name' => 'Testverein'], [
        'short_name' => 'TV',
        'nation_id' => $nation->id,
    ]);

    $athlete = Athlete::create([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'gender' => $athleteGender,
        'birth_date' => '2005-06-01',
        'club_id' => $club->id,
        'nation_id' => $nation->id,
    ]);

    $meet = Meet::create([
        'name' => 'Testmeeting',
        'nation_id' => $nation->id,
        'course' => $course,
        'start_date' => '2026-05-01',
    ]);

    $swimEvent = SwimEvent::create(array_merge([
        'meet_id' => $meet->id,
        'stroke_type_id' => stroke_wps2('FREE')->id,
        'distance' => 50,
        'relay_count' => 1,
        'gender' => 'M',
    ], $event));

    return Result::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => $swimEvent->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => 2637,
        'sport_class' => 'S10',
    ], $result));
}

function calculator_wps2(): WpsPointCalculator
{
    // Über den Container, da der Rechner seit Phase 5 den Umrechnungsservice benötigt.
    return app(WpsPointCalculator::class);
}

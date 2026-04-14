<?php

// Gemeinsame Pest-Helpers für Phase 5 (ClubEntry).
// Diese Datei wird in tests/Pest.php per require eingebunden.

use App\Models\Athlete;
use App\Models\AthleteSportClass;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimEvent;

function makeNation_p5(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], [
        'name_de' => 'Österreich',
        'name_en' => 'Austria',
        'is_active' => true,
    ]);
}

function makeClub_p5(): Club
{
    static $i = 0;
    $i++;

    return Club::create([
        'name' => 'Testverein '.$i,
        'short_name' => 'TV'.$i,
        'code' => 'TV'.$i,
        'nation_id' => makeNation_p5()->id,
    ]);
}

function makeAthlete_p5(Club $club, string $gender = 'M', array $sportClasses = ['S9']): Athlete
{
    static $j = 0;
    $j++;
    $athlete = Athlete::create([
        'first_name' => 'Max',
        'last_name' => 'Muster'.$j,
        'gender' => $gender,
        'nation_id' => makeNation_p5()->id,
        'club_id' => $club->id,
    ]);
    foreach ($sportClasses as $sc) {
        preg_match('/^(SB|SM|S)(\d+)$/', $sc, $m);
        AthleteSportClass::create([
            'athlete_id' => $athlete->id,
            'category' => $m[1],
            'class_number' => $m[2],
            'sport_class' => $sc,
        ]);
    }

    return $athlete;
}

function makeStrokeType_p5(string $code = 'FREE'): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $code], [
        'name_de' => $code,
        'name_en' => $code,
        'code' => strtolower($code),
    ]);
}

function makeEvent_p5(Meet $meet, array $attrs = []): SwimEvent
{
    return SwimEvent::create(array_merge([
        'meet_id' => $meet->id,
        'stroke_type_id' => makeStrokeType_p5()->id,
        'distance' => 100,
        'relay_count' => 1,
        'gender' => 'M',
        'sport_classes' => '9 10',
    ], $attrs));
}

function makeMeet_p5(): Meet
{
    return Meet::create([
        'name' => 'Testmeet',
        'nation_id' => makeNation_p5()->id,
        'course' => 'LCM',
        'start_date' => '2025-06-15',
    ]);
}

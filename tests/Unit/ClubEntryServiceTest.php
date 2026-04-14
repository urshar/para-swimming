<?php

use App\Models\Meet;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Services\ClubEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Tests: eligibleAthletes ───────────────────────────────────────────────────

describe('eligibleAthletes', function () {

    it('gibt Athleten mit passender Sportklasse zurück', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $event = makeEvent_p5($meet, ['sport_classes' => '9', 'gender' => 'M']);

        $eligible = makeAthlete_p5($club, 'M', ['S9']);
        makeAthlete_p5($club, 'M', ['S12']); // andere Klasse → nicht eligible

        $service = new ClubEntryService;
        $result = $service->eligibleAthletes($event, $club);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($eligible->id);
    });

    it('filtert nach Geschlecht', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $event = makeEvent_p5($meet, ['sport_classes' => '9', 'gender' => 'F']);

        makeAthlete_p5($club, 'M', ['S9']); // falsches Geschlecht
        $eligible = makeAthlete_p5($club, 'F', ['S9']);

        $service = new ClubEntryService;
        $result = $service->eligibleAthletes($event, $club);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($eligible->id);
    });

    it('gibt alle Athleten zurück wenn keine Sportklassen am Event', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $event = makeEvent_p5($meet, ['sport_classes' => null, 'gender' => 'M']);

        makeAthlete_p5($club, 'M', ['S9']);
        makeAthlete_p5($club, 'M', ['S12']);

        $service = new ClubEntryService;
        $result = $service->eligibleAthletes($event, $club);

        expect($result)->toHaveCount(2);
    });

    it('gender X im Event erlaubt alle Geschlechter', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $event = makeEvent_p5($meet, ['sport_classes' => '9', 'gender' => 'X']);

        makeAthlete_p5($club, 'M', ['S9']);
        makeAthlete_p5($club, 'F', ['S9']);

        $service = new ClubEntryService;
        $result = $service->eligibleAthletes($event, $club);

        expect($result)->toHaveCount(2);
    });

    it('gibt leere Collection zurück wenn kein Athlet passt', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $event = makeEvent_p5($meet, ['sport_classes' => '14', 'gender' => 'M']);

        makeAthlete_p5($club, 'M', ['S9']);

        $service = new ClubEntryService;
        $result = $service->eligibleAthletes($event, $club);

        expect($result)->toBeEmpty();
    });

})->group('club-entry-service');

// ── Tests: bestTimes ──────────────────────────────────────────────────────────

describe('bestTimes', function () {

    it('gibt Jahresbestzeit im richtigen Zeitraum zurück', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5(); // start_date = 2025-06-15
        $event = makeEvent_p5($meet);
        $athlete = makeAthlete_p5($club);

        // Gültige Bestzeit: 2024-01-01 bis 2025-06-14
        // Result direkt mit $event->id verknüpfen — Service sucht nach swim_event_id
        $validMeet = Meet::create([
            'name' => 'Vorjahresmeet',
            'nation_id' => makeNation_p5()->id,
            'course' => 'LCM',
            'start_date' => '2024-08-10',
        ]);
        Result::create([
            'meet_id' => $validMeet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id,
            'club_id' => $club->id,
            'swim_time' => 6000, // 01:00.00
            'status' => null,
        ]);

        // Außerhalb des Zeitraums (2023 → vor Vorjahr) — soll ignoriert werden
        $oldMeet = Meet::create([
            'name' => 'Altesmeet',
            'nation_id' => makeNation_p5()->id,
            'course' => 'LCM',
            'start_date' => '2023-05-01',
        ]);
        Result::create([
            'meet_id' => $oldMeet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id,
            'club_id' => $club->id,
            'swim_time' => 5800, // schneller, aber zu alt
            'status' => null,
        ]);

        $service = new ClubEntryService;
        $times = $service->bestTimes($athlete, $event, $meet);

        expect($times['LCM'])->toBe(6000);
        expect($times['SCM'])->toBeNull();
    });

    it('ignoriert DSQ/DNS Results (status != null)', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $event = makeEvent_p5($meet);
        $athlete = makeAthlete_p5($club);

        $otherMeet = Meet::create([
            'name' => 'Disq-Meet',
            'nation_id' => makeNation_p5()->id,
            'course' => 'LCM',
            'start_date' => '2024-03-01',
        ]);
        $otherEvent = SwimEvent::create([
            'meet_id' => $otherMeet->id,
            'stroke_type_id' => makeStrokeType_p5()->id,
            'distance' => 100,
            'relay_count' => 1,
            'gender' => 'M',
        ]);
        Result::create([
            'meet_id' => $otherMeet->id,
            'swim_event_id' => $otherEvent->id,
            'athlete_id' => $athlete->id,
            'club_id' => $club->id,
            'swim_time' => 5000,
            'status' => 'DSQ', // soll ignoriert werden
        ]);

        $service = new ClubEntryService;
        $times = $service->bestTimes($athlete, $meet->swimEvents()->first() ?? $event, $meet);

        expect($times['LCM'])->toBeNull();
    });

})->group('club-entry-service');

// ── Tests: Zeitformatierung ───────────────────────────────────────────────────

describe('formatTime / parseTime', function () {

    it('formatiert Hundertstelsekunden korrekt', function () {
        $service = new ClubEntryService;
        expect($service->formatTime(6345))->toBe('01:03.45');
        expect($service->formatTime(null))->toBeNull();
    });

    it('parst Zeitstring korrekt', function () {
        $service = new ClubEntryService;
        expect($service->parseTime('01:03.45'))->toBe(6345);
        expect($service->parseTime('NT'))->toBeNull();
    });

})->group('club-entry-service');

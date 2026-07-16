<?php

use App\Models\Athlete;
use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\WorldAquaticsPointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function makeWaBase_wa(): array
{
    $nation = Nation::forceCreate([
        'code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
    ]);
    $club = Club::create(['name' => 'Testverein', 'nation_id' => $nation->id, 'type' => 'CLUB']);
    $stroke = StrokeType::create([
        'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE',
    ]);

    $meet = Meet::create([
        'name' => 'Testbewerb', 'nation_id' => $nation->id, 'course' => 'LCM',
        'start_date' => '2023-06-01', 'end_date' => '2023-06-01', 'city' => 'Wien',
    ]);

    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => $stroke->id,
        'distance' => 100, 'relay_count' => 1, 'gender' => 'M',
        'session_number' => 1, 'event_number' => 1, 'round' => 'TIM',
    ]);

    $athlete = Athlete::create([
        'first_name' => 'Test', 'last_name' => 'Schwimmer', 'gender' => 'M',
        'birth_date' => '2000-01-01', 'nation_id' => $nation->id, 'club_id' => $club->id,
    ]);

    // Basiswert-Version, die das Meet-Datum abdeckt
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => '2026-12-31']);
    $category = BaseTimeCategory::create(['code' => 'LC_MEN', 'course' => 'LCM', 'gender' => 'M', 'label' => 'LC Men']);
    $discipline = BaseTimeDiscipline::create([
        'code' => '100FR', 'distance' => 100, 'relay_count' => 1, 'stroke_type_id' => $stroke->id,
    ]);
    $sportClass = BaseTimeSportClass::create(['code' => 'S9', 'sort_order' => 1]);

    // Basiszeit 60.00s (6000cs)
    BaseTime::create([
        'base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id, 'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => 6000, 'value_type' => BaseTime::TYPE_MANUAL,
    ]);

    return compact('nation', 'club', 'stroke', 'meet', 'event', 'athlete', 'version', 'category', 'discipline',
        'sportClass');
}

describe('WorldAquaticsPointsService', function () {
    it('berechnet P = 1000 x (B/T)^3 korrekt und normalisiert den Sportklassen-Präfix', function () {
        $fixture = makeWaBase_wa();

        // Schwimmzeit 65.00s bei S9-Klasse, aber mit "S9"-Präfix wie beim Freistil-Ergebnis üblich
        $result = Result::create([
            'meet_id' => $fixture['meet']->id, 'swim_event_id' => $fixture['event']->id,
            'athlete_id' => $fixture['athlete']->id, 'club_id' => $fixture['club']->id,
            'swim_time' => 6500, 'sport_class' => 'S9',
        ]);

        $points = (new WorldAquaticsPointsService)->calculatePoints($result, $fixture['meet']);

        // P = 1000 * (60/65)^3 = 1000 × 0.851599... = 851.599... → gerundet 852
        expect($points)->toBe((int) round(1000 * (60 / 65) ** 3));
    })->group('wa-points');

    it('normalisiert den SB/SM-Präfix auf die reine Sportklassen-Nummer', function () {
        $fixture = makeWaBase_wa();

        $result = Result::create([
            'meet_id' => $fixture['meet']->id, 'swim_event_id' => $fixture['event']->id,
            'athlete_id' => $fixture['athlete']->id, 'club_id' => $fixture['club']->id,
            'swim_time' => 6500, 'sport_class' => 'SB9', // Brust-Präfix, Basiswert-Tabelle kennt nur "S9"
        ]);

        $points = (new WorldAquaticsPointsService)->calculatePoints($result, $fixture['meet']);

        expect($points)->toBe((int) round(1000 * (60 / 65) ** 3));
    })->group('wa-points');

    it('gibt null zurück, wenn keine passende Basiswert-Kombination existiert', function () {
        $fixture = makeWaBase_wa();

        $result = Result::create([
            'meet_id' => $fixture['meet']->id, 'swim_event_id' => $fixture['event']->id,
            'athlete_id' => $fixture['athlete']->id, 'club_id' => $fixture['club']->id,
            'swim_time' => 6500, 'sport_class' => 'S99', // existiert nicht in der Basiswert-Tabelle
        ]);

        expect((new WorldAquaticsPointsService)->calculatePoints($result, $fixture['meet']))->toBeNull();
    })->group('wa-points');

    it('recalculateForMeet aktualisiert results.points für das ganze Meet und meldet Skips', function () {
        $fixture = makeWaBase_wa();

        Result::create([
            'meet_id' => $fixture['meet']->id, 'swim_event_id' => $fixture['event']->id,
            'athlete_id' => $fixture['athlete']->id, 'club_id' => $fixture['club']->id,
            'swim_time' => 6500, 'sport_class' => 'S9', 'points' => 1, // falscher Alt-Wert, muss überschrieben werden
        ]);
        $unresolvable = Result::create([
            'meet_id' => $fixture['meet']->id, 'swim_event_id' => $fixture['event']->id,
            'athlete_id' => $fixture['athlete']->id, 'club_id' => $fixture['club']->id,
            'swim_time' => 6500, 'sport_class' => 'S99', 'heat' => 2,
        ]);

        $summary = (new WorldAquaticsPointsService)->recalculateForMeet($fixture['meet']);

        expect($summary['updated'])->toBe(1)
            ->and($summary['skipped'])->toBe(1)
            ->and($unresolvable->fresh()->points)->toBeNull();
    })->group('wa-points');

    it('verwendet eine explizit übergebene Version statt der automatischen Zuordnung', function () {
        $fixture = makeWaBase_wa();

        // Zweite Version mit abweichendem Basiswert (70.00s statt 60.00s), ebenfalls gültig zum Meet-Datum...
        // ... wird hier aber bewusst NICHT über den Zeitraum, sondern per Parameter ausgewählt.
        $version2 = BaseTimeVersion::create([
            'label' => 'Alternative', 'valid_from' => '2030-01-01', 'valid_until' => null,
        ]);
        BaseTime::create([
            'base_time_version_id' => $version2->id, 'base_time_category_id' => $fixture['category']->id,
            'base_time_discipline_id' => $fixture['discipline']->id,
            'base_time_sport_class_id' => $fixture['sportClass']->id,
            'value_centiseconds' => 7000, 'value_type' => BaseTime::TYPE_MANUAL,
        ]);

        $result = Result::create([
            'meet_id' => $fixture['meet']->id, 'swim_event_id' => $fixture['event']->id,
            'athlete_id' => $fixture['athlete']->id, 'club_id' => $fixture['club']->id,
            'swim_time' => 6500, 'sport_class' => 'S9',
        ]);

        $automatic = (new WorldAquaticsPointsService)->calculatePoints($result, $fixture['meet']);
        $overridden = (new WorldAquaticsPointsService)->calculatePoints($result, $fixture['meet'], $version2);

        expect($automatic)->toBe((int) round(1000 * (60 / 65) ** 3))
            ->and($overridden)->toBe((int) round(1000 * (70 / 65) ** 3))
            ->and($overridden)->not->toBe($automatic);
    })->group('wa-points');

    it('resolveAutomaticVersion liefert die zum Meet-Datum passende Version', function () {
        $fixture = makeWaBase_wa();

        $resolved = (new WorldAquaticsPointsService)->resolveAutomaticVersion($fixture['meet']);

        expect($resolved->id)->toBe($fixture['version']->id);
    })->group('wa-points');

    it('verwendet bei Einzelbewerben mit gender=X das Geschlecht des Athleten statt "Mixed"', function () {
        $fixture = makeWaBase_wa();

        // Event ist organisatorisch als "Mixed" gelistet, obwohl es ein Einzelbewerb ist
        // (kommt bei manchen Meets vor) — der Athlet selbst ist aber eindeutig männlich.
        $fixture['event']->update(['gender' => 'X']);

        $result = Result::create([
            'meet_id' => $fixture['meet']->id, 'swim_event_id' => $fixture['event']->id,
            'athlete_id' => $fixture['athlete']->id, 'club_id' => $fixture['club']->id,
            'swim_time' => 6500, 'sport_class' => 'S9',
        ]);

        $points = (new WorldAquaticsPointsService)->calculatePoints($result, $fixture['meet']);

        // Muss trotz gender=X am Event über den Athleten (M) auf LC_MEN auflösen, nicht fehlschlagen.
        expect($points)->toBe((int) round(1000 * (60 / 65) ** 3));
    })->group('wa-points');
});

// ── findOutdatedResults ────────────────────────────────────────────────────────

describe('findOutdatedResults', function () {
    it('findet ein Ergebnis, dessen gespeicherte Punkte nicht mehr zur aktuellen Basiszeit passen', function () {
        $fixture = makeWaBase_wa();

        // Punkte wurden mit der ALTEN Basiszeit (z.B. 61.00s) berechnet und gespeichert...
        $result = Result::create([
            'meet_id' => $fixture['meet']->id, 'swim_event_id' => $fixture['event']->id,
            'athlete_id' => $fixture['athlete']->id, 'club_id' => $fixture['club']->id,
            'swim_time' => 6500, 'sport_class' => 'S9',
            'points' => (int) round(1000 * (61 / 65) ** 3), // veralteter Wert
        ]);

        // ...aber die Basiszeit wurde inzwischen auf 60.00s korrigiert (siehe makeWaBase_wa()).
        $outdated = (new WorldAquaticsPointsService)->findOutdatedResults($fixture['meet']);

        expect($outdated->pluck('id')->all())->toBe([$result->id]);
    })->group('wa-points');

    it('findet kein Ergebnis, dessen Punkte bereits korrekt sind', function () {
        $fixture = makeWaBase_wa();

        Result::create([
            'meet_id' => $fixture['meet']->id, 'swim_event_id' => $fixture['event']->id,
            'athlete_id' => $fixture['athlete']->id, 'club_id' => $fixture['club']->id,
            'swim_time' => 6500, 'sport_class' => 'S9',
            'points' => (int) round(1000 * (60 / 65) ** 3), // bereits aktuell
        ]);

        $outdated = (new WorldAquaticsPointsService)->findOutdatedResults($fixture['meet']);

        expect($outdated)->toHaveCount(0);
    })->group('wa-points');

    it('ignoriert Ergebnisse, für die sich gar keine Punkte berechnen lassen (kein falsch-positiver Treffer)', function () {
        $fixture = makeWaBase_wa();

        Result::create([
            'meet_id' => $fixture['meet']->id, 'swim_event_id' => $fixture['event']->id,
            'athlete_id' => $fixture['athlete']->id, 'club_id' => $fixture['club']->id,
            'swim_time' => 6500, 'sport_class' => 'S99', // keine Basiswert-Sportklasse vorhanden
            'points' => 999,
        ]);

        $outdated = (new WorldAquaticsPointsService)->findOutdatedResults($fixture['meet']);

        expect($outdated)->toHaveCount(0);
    })->group('wa-points');
});

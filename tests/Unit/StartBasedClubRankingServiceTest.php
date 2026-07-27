<?php

use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\StartBasedClubRankingService;
use App\Support\StartClubRankingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('start-based-club-ranking-p1');

// ── Helpers ──────────────────────────────────────────────────────────────────

function service_scr1(): StartBasedClubRankingService
{
    return new StartBasedClubRankingService;
}

/** Fortlaufender Zähler für eindeutige, aber deterministische Testwerte. */
function nextSeq_scr1(): int
{
    static $n = 0;

    return ++$n;
}

function makeNation_scr1(string $code): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeStroke_scr1(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']
    );
}

function makeClub_scr1(string $name, string $nationCode = 'AUT'): Club
{
    return Club::create(['name' => $name, 'nation_id' => makeNation_scr1($nationCode)->id]);
}

function makeAthlete_scr1(?Club $club = null): Athlete
{
    return Athlete::create([
        'first_name' => 'Max',
        'last_name' => 'Muster'.nextSeq_scr1(),
        'gender' => 'M',
        'nation_id' => makeNation_scr1('AUT')->id,
        'club_id' => $club?->id,
        'is_active' => true,
    ]);
}

function makeCup_scr1(int $year = 2026): Cup
{
    $version = BaseTimeVersion::create(['label' => "V$year", 'valid_from' => "$year-01-01"]);

    return Cup::create([
        'year' => $year,
        'name' => "ÖBSV Cup $year",
        'base_time_version_id' => $version->id,
        'rounds_count' => 1,
        'best_of_count' => 3,
    ]);
}

function makeMeet_scr1(?Cup $cup): Meet
{
    $year = $cup?->year ?? 2026;

    return Meet::create([
        'name' => 'Meet '.nextSeq_scr1(),
        'nation_id' => makeNation_scr1('AUT')->id,
        'start_date' => "$year-05-01",
        'cup_id' => $cup?->id,
    ]);
}

/**
 * Legt einen Einzelbewerb an. Der "Bewerb" wird über Distanz + Schwimmart
 * (hier stets Freistil) bestimmt; $round unterscheidet Vor-/Endlauf.
 */
function makeEvent_scr1(Meet $meet, int $distance, string $round = 'TIM', int $relayCount = 1): SwimEvent
{
    return SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => makeStroke_scr1()->id,
        'distance' => $distance,
        'round' => $round,
        'relay_count' => $relayCount,
    ]);
}

/** Legt ein Ergebnis an. $status = null → reguläres Ergebnis. */
function makeResult_scr1(Meet $meet, SwimEvent $event, Athlete $athlete, Club $club, ?string $status = null): Result
{
    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => 10000,
        'status' => $status,
        'sport_class' => 'S9',
    ]);
}

/** Zeile der Rangliste zu einem Vereinsnamen (oder null). */
function row_scr1(Collection $ranking, string $clubName): ?StartClubRankingResult
{
    return $ranking->firstWhere('clubName', $clubName);
}

// ── Tests ────────────────────────────────────────────────────────────────────

it('berücksichtigt nur Cup-Meets und ignoriert Nicht-Cup-Meets', function () {
    $cup = makeCup_scr1();
    $club = makeClub_scr1('Cup-Verein');
    $athlete = makeAthlete_scr1();

    $cupMeet = makeMeet_scr1($cup);
    makeResult_scr1($cupMeet, makeEvent_scr1($cupMeet, 100), $athlete, $club);

    // Gleicher Athlet, gleicher Verein, aber in einem Meet OHNE cup_id.
    $nonCupMeet = makeMeet_scr1(null);
    makeResult_scr1($nonCupMeet, makeEvent_scr1($nonCupMeet, 200), $athlete, $club);

    $ranking = service_scr1()->getRanking($cup);
    $row = row_scr1($ranking, 'Cup-Verein');

    expect($ranking)->toHaveCount(1)
        ->and($row->starts)->toBe(1)
        ->and($row->meets)->toBe(1)
        ->and($row->athletes)->toBe(1);
});

it('ignoriert Staffelstarts', function () {
    $cup = makeCup_scr1();
    $club = makeClub_scr1('Staffel-Verein');
    $athlete = makeAthlete_scr1();
    $meet = makeMeet_scr1($cup);

    makeResult_scr1($meet, makeEvent_scr1($meet, 100), $athlete, $club);
    // Staffelbewerb (relay_count > 1) → darf nicht zählen.
    makeResult_scr1($meet, makeEvent_scr1($meet, 400, relayCount: 4), $athlete, $club);

    $row = row_scr1(service_scr1()->getRanking($cup), 'Staffel-Verein');

    expect($row->starts)->toBe(1);
});

it('zählt reguläre, DSQ- und DNF-Ergebnisse, aber nicht DNS/SICK/WDR/EXH', function () {
    $cup = makeCup_scr1();
    $club = makeClub_scr1('Status-Verein');
    $athlete = makeAthlete_scr1();
    $meet = makeMeet_scr1($cup);

    // Je Status ein eigener Bewerb (unterschiedliche Distanz), damit die
    // Deduplizierung nicht greift und jeder Status einzeln sichtbar wird.
    makeResult_scr1($meet, makeEvent_scr1($meet, 50), $athlete, $club);          // regulär → zählt
    makeResult_scr1($meet, makeEvent_scr1($meet, 100), $athlete, $club, 'DSQ');  // zählt
    makeResult_scr1($meet, makeEvent_scr1($meet, 200), $athlete, $club, 'DNF');  // zählt
    makeResult_scr1($meet, makeEvent_scr1($meet, 400), $athlete, $club, 'DNS');  // ignoriert
    makeResult_scr1($meet, makeEvent_scr1($meet, 800), $athlete, $club, 'SICK'); // ignoriert
    makeResult_scr1($meet, makeEvent_scr1($meet, 1500), $athlete, $club, 'WDR'); // ignoriert
    makeResult_scr1($meet, makeEvent_scr1($meet, 25), $athlete, $club, 'EXH');   // ignoriert

    $row = row_scr1(service_scr1()->getRanking($cup), 'Status-Verein');

    expect($row->starts)->toBe(3);
});

it('fasst Vor-/Endlauf und Heats desselben Bewerbs zu einem Start zusammen', function () {
    $cup = makeCup_scr1();
    $club = makeClub_scr1('Runden-Verein');
    $athlete = makeAthlete_scr1();
    $meet = makeMeet_scr1($cup);

    // Gleicher Bewerb (100 FREE) als Vorlauf UND Finale = getrennte Events,
    // aber nur EIN Start.
    makeResult_scr1($meet, makeEvent_scr1($meet, 100, 'PRE'), $athlete, $club);
    makeResult_scr1($meet, makeEvent_scr1($meet, 100, 'FIN'), $athlete, $club);
    // Ein anderer Bewerb (50 FREE) = eigener Start.
    makeResult_scr1($meet, makeEvent_scr1($meet, 50), $athlete, $club);

    $row = row_scr1(service_scr1()->getRanking($cup), 'Runden-Verein');

    expect($row->starts)->toBe(2)
        ->and($row->athletes)->toBe(1);
});

it('zählt Starts, Athleten und Meets korrekt über mehrere Meets', function () {
    $cup = makeCup_scr1();
    $club = makeClub_scr1('Zähl-Verein');
    $a1 = makeAthlete_scr1();
    $a2 = makeAthlete_scr1();

    $meet1 = makeMeet_scr1($cup);
    $meet2 = makeMeet_scr1($cup);

    // Athlet 1: 2 Bewerbe in Meet 1 + 1 Bewerb in Meet 2 = 3 Starts.
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 50), $a1, $club);
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 100), $a1, $club);
    makeResult_scr1($meet2, makeEvent_scr1($meet2, 50), $a1, $club);
    // Athlet 2: 1 Bewerb in Meet 1 = 1 Start.
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 200), $a2, $club);

    $row = row_scr1(service_scr1()->getRanking($cup), 'Zähl-Verein');

    expect($row->starts)->toBe(4)
        ->and($row->athletes)->toBe(2)
        ->and($row->meets)->toBe(2);
});

it('funktioniert auch für vergangene Cup-Jahre', function () {
    $cup = makeCup_scr1(2023);
    $club = makeClub_scr1('Historie-Verein');
    $athlete = makeAthlete_scr1();
    $meet = makeMeet_scr1($cup);

    makeResult_scr1($meet, makeEvent_scr1($meet, 100), $athlete, $club);

    $row = row_scr1(service_scr1()->getRanking($cup), 'Historie-Verein');

    expect($row)->not->toBeNull()
        ->and($row->starts)->toBe(1);
});

it('reiht nach Starts, dann Athleten, dann Cup-Meets', function () {
    $cup = makeCup_scr1();
    $meet1 = makeMeet_scr1($cup);
    $meet2 = makeMeet_scr1($cup);

    // Beta: 3 Starts, 3 Athleten, 2 Meets.
    $beta = makeClub_scr1('Beta');
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 100), makeAthlete_scr1(), $beta);
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 100), makeAthlete_scr1(), $beta);
    makeResult_scr1($meet2, makeEvent_scr1($meet2, 100), makeAthlete_scr1(), $beta);

    // Gamma: 3 Starts, 3 Athleten, 1 Meet.
    $gamma = makeClub_scr1('Gamma');
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 100), makeAthlete_scr1(), $gamma);
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 100), makeAthlete_scr1(), $gamma);
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 100), makeAthlete_scr1(), $gamma);

    // Alpha: 3 Starts, 1 Athlet, 1 Meet.
    $alpha = makeClub_scr1('Alpha');
    $solo = makeAthlete_scr1();
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 50), $solo, $alpha);
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 100), $solo, $alpha);
    makeResult_scr1($meet1, makeEvent_scr1($meet1, 200), $solo, $alpha);

    $ranking = service_scr1()->getRanking($cup);

    expect($ranking->pluck('clubName')->all())->toBe(['Beta', 'Gamma', 'Alpha'])
        ->and($ranking->pluck('rank')->all())->toBe([1, 2, 3]);
});

it('vergibt bei identischen Kriterien denselben Rang und reiht alphabetisch', function () {
    $cup = makeCup_scr1();
    $meet = makeMeet_scr1($cup);

    // Adler und Zebra sind identisch: je 2 Starts, 2 Athleten, 1 Meet.
    foreach (['Zebra', 'Adler'] as $name) {
        $club = makeClub_scr1($name);
        makeResult_scr1($meet, makeEvent_scr1($meet, 100), makeAthlete_scr1(), $club);
        makeResult_scr1($meet, makeEvent_scr1($meet, 100), makeAthlete_scr1(), $club);
    }

    // Mitte: nur 1 Start.
    $mitte = makeClub_scr1('Mitte');
    makeResult_scr1($meet, makeEvent_scr1($meet, 100), makeAthlete_scr1(), $mitte);

    $ranking = service_scr1()->getRanking($cup);

    expect($ranking->pluck('clubName')->all())->toBe(['Adler', 'Zebra', 'Mitte'])
        ->and($ranking->pluck('rank')->all())->toBe([1, 1, 3]);
});

it('verwendet den Startverein des Ergebnisses, nicht den aktuellen Verein des Athleten', function () {
    $cup = makeCup_scr1();
    $altClub = makeClub_scr1('Alt-Verein');
    $neuClub = makeClub_scr1('Neu-Verein');

    // Athlet gehört inzwischen dem Neu-Verein an …
    $athlete = makeAthlete_scr1($neuClub);
    $meet = makeMeet_scr1($cup);
    // … ist beim Ergebnis aber für den Alt-Verein gestartet.
    makeResult_scr1($meet, makeEvent_scr1($meet, 100), $athlete, $altClub);

    $ranking = service_scr1()->getRanking($cup);

    expect(row_scr1($ranking, 'Alt-Verein')?->starts)->toBe(1)
        ->and(row_scr1($ranking, 'Neu-Verein'))->toBeNull();
});

it('schließt ausländische Vereine standardmäßig aus', function () {
    $cup = makeCup_scr1();
    $meet = makeMeet_scr1($cup);

    $austria = makeClub_scr1('Austria');
    $germania = makeClub_scr1('Germania', 'GER');
    makeResult_scr1($meet, makeEvent_scr1($meet, 100), makeAthlete_scr1(), $austria);
    makeResult_scr1($meet, makeEvent_scr1($meet, 100), makeAthlete_scr1(), $germania);

    $ranking = service_scr1()->getRanking($cup);

    expect($ranking)->toHaveCount(1)
        ->and(row_scr1($ranking, 'Austria'))->not->toBeNull()
        ->and(row_scr1($ranking, 'Germania'))->toBeNull();
});

it('wertet ausländische Vereine, wenn per Argument aktiviert', function () {
    $cup = makeCup_scr1();
    $meet = makeMeet_scr1($cup);

    $austria = makeClub_scr1('Austria');
    $germania = makeClub_scr1('Germania', 'GER');
    makeResult_scr1($meet, makeEvent_scr1($meet, 100), makeAthlete_scr1(), $austria);
    makeResult_scr1($meet, makeEvent_scr1($meet, 100), makeAthlete_scr1(), $germania);

    $ranking = service_scr1()->getRanking($cup, includeForeignClubs: true);

    expect($ranking)->toHaveCount(2)
        ->and(row_scr1($ranking, 'Germania'))->not->toBeNull()
        ->and(row_scr1($ranking, 'Austria'))->not->toBeNull();
});

it('respektiert die Konfiguration include_foreign_clubs', function () {
    config()->set('cup_club_ranking.include_foreign_clubs', true);

    $cup = makeCup_scr1();
    $meet = makeMeet_scr1($cup);

    makeResult_scr1($meet, makeEvent_scr1($meet, 100), makeAthlete_scr1(), makeClub_scr1('Austria'));
    makeResult_scr1($meet, makeEvent_scr1($meet, 100), makeAthlete_scr1(), makeClub_scr1('Germania', 'GER'));

    expect(service_scr1()->getRanking($cup))->toHaveCount(2);
});

it('liefert eine leere Rangliste, wenn der Cup keine Meets hat', function () {
    $cup = makeCup_scr1();

    expect(service_scr1()->getRanking($cup))->toBeEmpty();
});

it('liefert eine leere Rangliste, wenn nur ungültige Ergebnisse vorliegen', function () {
    $cup = makeCup_scr1();
    $club = makeClub_scr1('Nur-Ungültig');
    $athlete = makeAthlete_scr1();
    $meet = makeMeet_scr1($cup);

    makeResult_scr1($meet, makeEvent_scr1($meet, 100), $athlete, $club, 'DNS');
    makeResult_scr1($meet, makeEvent_scr1($meet, 200), $athlete, $club, 'EXH');

    expect(service_scr1()->getRanking($cup))->toBeEmpty();
});

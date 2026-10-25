<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ForeignStart;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-2-p3');

// LENEX 2.0 (docs/specs/lenex-2.0-import.md, Phase 3): ein RESULT je Wertungsgruppe → ein Ergebnis je Start, Platz
// aus der Klassengruppe bzw. der offenen Gruppe, Ersatz-ID statt resultid. Nutzt die global geladenen _p5-Helper
// (tests/helpers_p5.php).

function fixture_l2p3(string $name): string
{
    return base_path('tests/Fixtures/lenex/'.$name);
}

function setup_l2p3(): Club
{
    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $stroke) {
        makeStrokeType_p5($stroke);
    }
    makeNation_p5();
    Nation::create(['code' => 'GER', 'name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);
    Nation::create(['code' => 'HUN', 'name_de' => 'Ungarn', 'name_en' => 'Hungary', 'is_active' => true]);

    return makeClub_p5();
}

function athlete_l2p3(Club $club, string $first, string $last, string $gender, string $birthDate): Athlete
{
    return Athlete::create([
        'first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'birth_date' => $birthDate,
        'nation_id' => makeNation_p5()->id, 'club_id' => $club->id,
    ]);
}

/**
 * Österreicher der sem2004-Datei (NPC Austria) mit Heimverein, Import mit Nationenfilter AUT.
 *
 * @throws Exception
 */
function importSem2004_l2p3(?int $meetId = null): array
{
    return (new LenexParserService)->import(fixture_l2p3('sem2004-results.lef'), new LenexResolverService, $meetId, ['only_nation' => 'AUT']);
}

function resultOf_l2p3(Athlete $athlete, int $eventNumber): Result
{
    return Result::where('athlete_id', $athlete->id)
        ->where('swim_event_id', SwimEvent::where('event_number', $eventNumber)->value('id'))
        ->sole();
}

it('legt je Start ein Ergebnis mit dem Platz der eigenen Startklasse an', /** @throws Exception */ function () {
    $club = setup_l2p3();
    $muster = athlete_l2p3($club, 'Max', 'Muster', 'M', '1975-01-01');
    $beispiel = athlete_l2p3($club, 'Jörg', 'Beispiel', 'M', '2002-01-01');

    $import = importSem2004_l2p3();

    expect(Result::count())->toBe(7)
        ->and($import['stats']['results'])->toBe(7)
        // 200 m Freistil: Startklasse S5 (Platz 7), nicht Masters I (2) oder Offene Wertung (42).
        ->and(resultOf_l2p3($muster, 1)->place)->toBe(7)
        // 100 m Brust: Startklasse SB4 (3), nicht "Startklasse AB" (1).
        ->and(resultOf_l2p3($muster, 5)->place)->toBe(3)
        // 200 m Lagen: Startklasse SM5 (2).
        ->and(resultOf_l2p3($muster, 7)->place)->toBe(2)
        // B-Finale: nur Offene Wertung.
        ->and(resultOf_l2p3($muster, 201)->place)->toBe(4)
        ->and(resultOf_l2p3($muster, 1)->points)->toBe(512)
        ->and(resultOf_l2p3($muster, 1)->splits()->count())->toBe(3)
        ->and(resultOf_l2p3($muster, 1)->lenex_result_id)->toBe('a365-e1')
        ->and(resultOf_l2p3($beispiel, 1)->place)->toBe(1)
        ->and(resultOf_l2p3($beispiel, 5)->place)->toBeNull()
        ->and(resultOf_l2p3($beispiel, 5)->status)->toBe('DSQ')
        ->and(resultOf_l2p3($beispiel, 7)->status)->toBe('DNS');
});

it('legt beim erneuten Import nichts doppelt an', /** @throws Exception */ function () {
    $club = setup_l2p3();
    athlete_l2p3($club, 'Max', 'Muster', 'M', '1975-01-01');
    athlete_l2p3($club, 'Jörg', 'Beispiel', 'M', '2002-01-01');

    importSem2004_l2p3();
    $ids = Result::orderBy('id')->pluck('id')->all();
    $again = importSem2004_l2p3(Meet::sole()->id);

    expect(Result::orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and($again['stats']['results_new'])->toBe(0)
        ->and($again['stats']['results_ambiguous'])->toBe(0);
});

it('übernimmt die Staffel mit Platz und Ersatz-ID', /** @throws Exception */ function () {
    $club = setup_l2p3();
    // Staffeln brauchen den Verein aus der Datei: "NPC Austria" als Verband (Zuordnung über die Klärungsseite, Phase 4).
    Club::create(['name' => 'NPC Austria', 'nation_id' => makeNation_p5()->id, 'type' => Club::TYPE_VERBAND]);
    athlete_l2p3($club, 'Max', 'Muster', 'M', '1975-01-01');
    athlete_l2p3($club, 'Jörg', 'Beispiel', 'M', '2002-01-01');
    athlete_l2p3($club, 'Lena', 'Probe', 'F', '1990-01-01');
    athlete_l2p3($club, 'Sara', 'Versuch', 'F', '1994-01-01');

    importSem2004_l2p3();
    $relay = RelayResult::sole();

    expect($relay->place)->toBe(3)
        ->and($relay->lenex_result_id)->toBe('r'.substr(md5('NPC Austria'), 0, 8).'-1-e11')
        ->and($relay->members()->whereNotNull('athlete_id')->count())->toBe(4);
});

it('legt Gäste je Start nur einmal an, mit dem Platz ihrer Startklasse', /** @throws Exception */ function () {
    setup_l2p3();

    (new LenexParserService)->import(fixture_l2p3('sem2004-results.lef'), new LenexResolverService);
    $starts = ForeignStart::orderBy('swim_event_id')->get();

    expect($starts)->toHaveCount(2)
        ->and($starts[0]->place)->toBe(1)
        ->and($starts[0]->lenex_result_id)->toBe('a98-e1')
        ->and($starts[1]->place)->toBeNull()
        ->and($starts[1]->status)->toBe('WDR');
});

it('nimmt bei Dateien ohne Startklassen den Platz der offenen Gruppe', /** @throws Exception */ function () {
    $club = setup_l2p3();
    $muster = athlete_l2p3($club, 'Anna', 'Muster', 'F', '2006-01-01');

    (new LenexParserService)->import(fixture_l2p3('msecm-results.lef'), new LenexResolverService, null, ['only_nation' => 'AUT']);
    $result = resultOf_l2p3($muster, 43);

    // "Allg. Klasse" (20), nicht "Jugend II+I" (3).
    expect(Result::where('athlete_id', $muster->id)->count())->toBe(1)
        ->and($result->place)->toBe(20)
        ->and($result->reaction_time)->toBe(71)
        ->and($result->lenex_result_id)->toBe('a2-e3');
});

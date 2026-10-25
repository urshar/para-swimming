<?php

use App\Http\Controllers\LenexImportController;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Nation;
use App\Models\Result;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-federation-home-club-p5');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function club_lfh5(?string $type = null): Club
{
    $club = makeClub_p5();
    if ($type !== null) {
        $club->update(['type' => $type]);
    }

    return $club;
}

function athlete_lfh5(?Club $home): Athlete
{
    makeStrokeType_p5();
    $athlete = makeAthlete_p5($home ?? makeClub_p5(), 'F');
    $athlete->update(['last_name' => 'Hummel', 'license' => 'LIC1', 'club_id' => $home?->id]);

    return $athlete;
}

/** LENEX-Ergebnisimport; liefert die Statistik (Exception → RuntimeException). */
function import_lfh5(string $xml): array
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_lfh5').'.lef';
    file_put_contents($path, $xml);

    try {
        return (new LenexParserService)->import($path, new LenexResolverService)['stats'];
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

/** Ergebnisdatei: eine Athletin mit einem Einzelergebnis unter dem Verein $fileClub (Wettkampf in Tschechien). */
function resultXml_lfh5(Club $fileClub): string
{
    Nation::firstOrCreate(['code' => 'CZE'], ['name_de' => 'Tschechien', 'name_en' => 'Czechia', 'is_active' => true]);

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Czech Open" city="Brno" nation="CZE" course="LCM">
<SESSIONS><SESSION number="1" date="2018-06-02"><EVENTS>
  <EVENT eventid="1059" number="2" gender="F" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" /></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$fileClub->name.'" code="'.$fileClub->code.'" nation="AUT"><ATHLETES>
  <ATHLETE athleteid="1" lastname="Hummel" firstname="Anna" gender="F" birthdate="2000-01-01" license="LIC1">
    <HANDICAP free="11" breast="11" medley="11" />
    <RESULTS><RESULT eventid="1059" resultid="501" swimtime="00:00:39.52" /></RESULTS>
  </ATHLETE>
</ATHLETES></CLUB></CLUBS></MEET></MEETS></LENEX>';
}

it('ordnet Einzelergebnisse unter einem Verband dem Heimverein des Athleten zu', function () {
    $national = club_lfh5(Club::TYPE_VERBAND);
    $wien = club_lfh5();
    athlete_lfh5($wien);

    $stats = import_lfh5(resultXml_lfh5($national));

    expect(Result::sole()->club_id)->toBe($wien->id)
        ->and($stats['results'])->toBe(1)
        ->and($stats['results_home_club'])->toBe(1);
});

it('lässt den Verein der Datei stehen, wenn er kein Verband ist', function () {
    $other = club_lfh5();
    $wien = club_lfh5();
    athlete_lfh5($wien);

    $stats = import_lfh5(resultXml_lfh5($other));

    expect(Result::sole()->club_id)->toBe($other->id)
        ->and($stats['results_home_club'])->toBe(0);
});

it('bleibt beim Verband, wenn der Athlet keinen eigenen Heimverein hat', function (bool $homeIsFederation) {
    $national = club_lfh5(Club::TYPE_VERBAND);
    athlete_lfh5($homeIsFederation ? club_lfh5(Club::TYPE_VERBAND) : null);

    $stats = import_lfh5(resultXml_lfh5($national));

    expect(Result::sole()->club_id)->toBe($national->id)
        ->and($stats['results_home_club'])->toBe(0);
})->with(['ohne Heimverein' => false, 'Heimverein ist selbst ein Verband' => true]);

it('nennt die umgehängten Ergebnisse in der Import-Rückmeldung', function () {
    $national = club_lfh5(Club::TYPE_VERBAND);
    athlete_lfh5(club_lfh5());
    $stats = import_lfh5(resultXml_lfh5($national));

    try {
        $response = (new ReflectionMethod(LenexImportController::class, 'redirectAfterImport'))
            ->invoke(app(LenexImportController::class), ['type' => 'results', 'stats' => $stats]);
    } catch (ReflectionException $e) {
        throw new RuntimeException('Rückmeldung nicht ermittelbar: '.$e->getMessage(), previous: $e);
    }

    expect($response->getSession()->get('success'))
        ->toContain('1 Ergebnis(se) unter einem Verband dem Heimverein des Athleten zugeordnet.');
});

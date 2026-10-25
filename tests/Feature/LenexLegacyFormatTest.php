<?php

use App\Models\Nation;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-2-p1');

// LENEX 2.0 (docs/specs/lenex-2.0-import.md): anonymisierte Auszüge aus echten Ergebnisdateien von sem2004
// (IDM Berlin 2018) und MSECM (Wiener Lagen Cup 2019), ISO-8859-1. Nutzt die global geladenen _p5-Helper
// (tests/helpers_p5.php).

function fixture_l2p1(string $name): string
{
    return base_path('tests/Fixtures/lenex/'.$name);
}

/** Splash-Datei (LENEX 3.0) mit Plätzen in AGEGROUP > RANKINGS. */
function splashFile_l2p1(): string
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_l2p1').'.lef';
    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><CONSTRUCTOR name="SPLASH Meet Manager 11" version="11.80000" />
<MEETS><MEET name="Testmeeting" city="Wien" nation="AUT" course="LCM">
<SESSIONS><SESSION number="1" date="2026-05-05"><EVENTS>
  <EVENT eventid="1" number="1"><SWIMSTYLE distance="100" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" agemax="-1" agemin="-1" name="S9"><RANKINGS>
      <RANKING order="1" place="1" resultid="501" /></RANKINGS></AGEGROUP></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="Testverein" code="TV" nation="AUT"><ATHLETES>
  <ATHLETE athleteid="1" lastname="Hummel" firstname="Anna" gender="F" birthdate="2000-01-01">
    <RESULTS><RESULT eventid="1" resultid="501" swimtime="00:01:10.00" /></RESULTS></ATHLETE>
</ATHLETES></CLUB></CLUBS></MEET></MEETS></LENEX>');

    return $path;
}

it('erkennt eine sem2004-Datei als LENEX 2.0 mit Plätzen am RESULT', /** @throws Exception */ function () {
    $parser = new LenexParserService;
    $path = fixture_l2p1('sem2004-results.lef');
    $format = $parser->fileFormatFromFile($path);
    $athletes = $parser->loadXml($path)->xpath('//ATHLETE');

    expect($parser->detectTypeFromFile($path))->toBe('results')
        ->and($format['version'])->toBe('2.0')
        ->and($format['constructor'])->toBe("swimming event management 2004 'sem2004'")
        ->and($format['constructor_version'])->toBe('3.7.622')
        ->and($format['label'])->toBe('LENEX 2.0 (sem2004)')
        ->and($format['places_in_results'])->toBeTrue()
        // ISO-8859-1 wird richtig gelesen.
        ->and((string) $athletes[1]['firstname'])->toBe('Jörg');
});

it('erkennt eine MSECM-Datei als LENEX 2.0 mit Plätzen am RESULT', /** @throws Exception */ function () {
    $parser = new LenexParserService;
    $path = fixture_l2p1('msecm-results.lef');
    $format = $parser->fileFormatFromFile($path);

    expect($parser->detectTypeFromFile($path))->toBe('results')
        ->and($format['version'])->toBe('2.0')
        ->and($format['label'])->toBe('LENEX 2.0 (MSECM Export Module)')
        ->and($format['places_in_results'])->toBeTrue();
});

it('erkennt eine Splash-Datei mit RANKINGS nicht als Plätze am RESULT', /** @throws Exception */ function () {
    $format = (new LenexParserService)->fileFormatFromFile(splashFile_l2p1());

    expect($format['version'])->toBe('3.0')
        ->and($format['label'])->toBe('LENEX 3.0 (SPLASH Meet Manager 11)')
        ->and($format['places_in_results'])->toBeFalse();
});

it('nennt das Format im Ergebnis des Imports', /** @throws Exception */ function () {
    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $stroke) {
        makeStrokeType_p5($stroke);
    }
    makeNation_p5();
    Nation::create(['code' => 'GER', 'name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);

    $result = (new LenexParserService)->import(fixture_l2p1('sem2004-results.lef'), new LenexResolverService, null, ['only_nation' => 'AUT']);

    expect($result['type'])->toBe('results')
        ->and($result['format']['label'])->toBe('LENEX 2.0 (sem2004)')
        ->and($result['format']['places_in_results'])->toBeTrue();
});

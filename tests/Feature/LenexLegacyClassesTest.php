<?php

use App\Models\Meet;
use App\Models\Nation;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-2-p2');

// LENEX 2.0 (docs/specs/lenex-2.0-import.md, Phase 2): Klassen aus Gruppennamen mit Präfix ("Startklasse S4"), nur
// Klassengruppen und die offene Gruppe als Wertungsgruppen. Nutzt die global geladenen _p5-Helper
// (tests/helpers_p5.php).

function fixture_l2p2(string $name): string
{
    return base_path('tests/Fixtures/lenex/'.$name);
}

/** @throws Exception */
function agegroup_l2p2(string $name, int $ageMin = -1, int $ageMax = -1): SimpleXMLElement
{
    return new SimpleXMLElement('<AGEGROUP agegroupid="1" agemin="'.$ageMin.'" agemax="'.$ageMax.'" name="'.$name.'" />');
}

function groupNames_l2p2(int $eventNumber): array
{
    return ScoringGroup::where('swim_event_id', SwimEvent::where('event_number', $eventNumber)->sole()->id)
        ->orderBy('sort_order')
        ->pluck('sport_classes', 'name')
        ->all();
}

/** @throws Exception */
function importSem2004_l2p2(): void
{
    foreach (['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'] as $stroke) {
        makeStrokeType_p5($stroke);
    }
    makeNation_p5();
    Nation::create(['code' => 'GER', 'name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);

    (new LenexParserService)->import(fixture_l2p2('sem2004-results.lef'), new LenexResolverService, null, ['only_nation' => 'AUT']);
}

it('liest die Klasse aus dem Gruppennamen, auch hinter einem Präfix', /** @throws Exception */ function (string $name, string $expected) {
    expect(LenexParserService::agegroupHandicap(agegroup_l2p2($name)))->toBe($expected);
})->with([
    'sem2004 S' => ['Startklasse S4', '4'],
    'sem2004 SB' => ['Startklasse SB4', '4'],
    'sem2004 SM' => ['Startklasse SM14', '14'],
    'Klasse' => ['Klasse S5', '5'],
    'Startklasse AB' => ['Startklasse AB', ''],
    'Offene Wertung' => ['Offene Wertung', ''],
    'Jugendwertung' => ['Jugendwertung', ''],
    'Splash S' => ['S4', '4'],
    'Splash SB' => ['SB2', '2'],
    'Splash Bereich' => ['S1 - 10', '1,2,3,4,5,6,7,8,9,10'],
]);

it('findet die offene Gruppe nach Alter, nicht nach Namen', /** @throws Exception */ function () {
    $parser = new LenexParserService;
    $events = fn (string $file) => $parser->loadXml(fixture_l2p2($file))->xpath('//EVENT');
    $openName = fn (SimpleXMLElement $eventXml) => (string) (LenexParserService::openAgegroup($eventXml->AGEGROUPS)['name'] ?? '');
    $sem2004 = $events('sem2004-results.lef');
    $msecm = $events('msecm-results.lef');

    $onlyAgeGroups = new SimpleXMLElement('<AGEGROUPS><AGEGROUP agemin="10" agemax="11" name="Jugend D" />'
        .'<AGEGROUP agemin="-1" agemax="-1" name="Startklasse AB" /><AGEGROUP agemin="-1" agemax="-1" name="Startklasse S4" /></AGEGROUPS>');

    expect($openName($sem2004[0]))->toBe('Offene Wertung')
        ->and($openName($sem2004[1]))->toBe('Offene Wertung')
        ->and($openName($msecm[0]))->toBe('Offene Klasse')
        ->and($openName($msecm[1]))->toBe('Allg. Klasse')
        ->and(LenexParserService::openAgegroup($onlyAgeGroups))->toBeNull();
});

it('übernimmt bei LENEX 2.0 nur Klassengruppen und die offene Gruppe', /** @throws Exception */ function () {
    importSem2004_l2p2();

    expect(groupNames_l2p2(1))->toBe([
        'Offene Wertung' => null, 'Startklasse S4' => '4', 'Startklasse S5' => '5', 'Startklasse S9' => '9',
    ])
        ->and(groupNames_l2p2(5))->toBe(['Offene Wertung' => null, 'Startklasse SB4' => '4', 'Startklasse SB8' => '8'])
        ->and(groupNames_l2p2(7))->toBe(['Offene Wertung' => null, 'Startklasse SM5' => '5', 'Startklasse SM9' => '9'])
        ->and(groupNames_l2p2(201))->toBe(['Offene Wertung' => null])
        ->and(SwimEvent::where('event_number', 1)->value('sport_classes'))->toBe('4 5 9')
        ->and(SwimEvent::where('event_number', 5)->value('sport_classes'))->toBe('4 8')
        ->and(SwimEvent::where('event_number', 201)->value('sport_classes'))->toBeNull();
});

it('entfernt beim erneuten Import Gruppen eines früheren Imports, die nicht mehr übernommen werden', /** @throws Exception */ function () {
    importSem2004_l2p2();
    $eventId = SwimEvent::where('event_number', 1)->value('id');
    // Stand nach einem Import vor Phase 2 (Wettkampf 25 auf dev): Altersgruppe und "Startklasse AB" angelegt.
    foreach (['4' => 'Jugend A', '24' => 'Startklasse AB'] as $lenexId => $name) {
        ScoringGroup::create(['swim_event_id' => $eventId, 'lenex_agegroup_id' => $lenexId, 'name' => $name, 'gender' => 'A', 'sort_order' => 20]);
    }
    ScoringGroup::create(['swim_event_id' => $eventId, 'name' => 'Eigene Gruppe', 'gender' => 'A', 'sort_order' => 30]);

    (new LenexParserService)->import(fixture_l2p2('sem2004-results.lef'), new LenexResolverService, Meet::sole()->id, ['only_nation' => 'AUT']);

    expect(array_keys(groupNames_l2p2(1)))->toBe([
        'Offene Wertung', 'Startklasse S4', 'Startklasse S5', 'Startklasse S9', 'Eigene Gruppe',
    ]);
});

it('übernimmt bei MSECM nur die offene Gruppe', /** @throws Exception */ function () {
    foreach (['FREE', 'BACK'] as $stroke) {
        makeStrokeType_p5($stroke);
    }
    makeNation_p5();

    (new LenexParserService)->import(fixture_l2p2('msecm-results.lef'), new LenexResolverService, null, ['only_nation' => 'AUT']);

    expect(groupNames_l2p2(43))->toBe(['Allg. Klasse' => null])
        ->and(groupNames_l2p2(41))->toBe(['Offene Klasse' => null]);
});

it('fragt nur Bewerbe ohne Startklassen als mögliche Rahmenbewerbe ab', /** @throws Exception */ function () {
    $unclassified = (new LenexParserService)->unclassifiedEvents(fixture_l2p2('sem2004-results.lef'));

    expect(array_column($unclassified, 'number'))->toBe([11, 201]);
});

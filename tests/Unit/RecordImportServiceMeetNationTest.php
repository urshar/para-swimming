<?php

use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Services\RecordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('record-import');

/**
 * Deckt eine konkrete Nutzerfrage ab: Wird beim LENEX-Import einer Rekorddatei das
 * Austragungsland (MEETINFO@nation) übernommen? Reale ÖBSV-Rekorddateien
 * (storage/app/private/record-imports/*.lxf) tragen dieses Attribut tatsächlich und mit
 * variierenden Werten (982× AUT, aber auch GER/GBR/SUI/BRA/GRE/ARG/AUS) — kein theoretisches
 * LENEX-Feld, sondern in der Praxis genutzt.
 */
function makeLenexFixture_ri(string $meetNation = 'GER'): string
{
    $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <LENEX version="3.0">
          <RECORDLISTS>
            <RECORDLIST type="AUT" course="LCM" gender="F" handicap="6">
              <RECORDS>
                <RECORD swimtime="1:00.00">
                  <SWIMSTYLE stroke="FREE" distance="50" relaycount="1" />
                  <MEETINFO name="Test Meet" city="Berlin" date="2024-05-01" nation="{$meetNation}" />
                  <ATHLETE lastname="Testimport" firstname="Erika" birthdate="2000-01-01" gender="F">
                    <CLUB nation="AUT" name="Testverein Import" code="TVI" />
                  </ATHLETE>
                </RECORD>
              </RECORDS>
            </RECORDLIST>
          </RECORDLISTS>
        </LENEX>
        XML;

    $path = Storage::disk('local')->path('record-import-test.xml');
    file_put_contents($path, $xml);

    return $path;
}

it('übernimmt MEETINFO@nation aus der LENEX-Datei als meet_nation_id', function () {
    Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
    $ger = Nation::firstOrCreate(['code' => 'GER'], ['name_de' => 'Deutschland', 'name_en' => 'Germany']);

    StrokeType::firstOrCreate(['lenex_code' => 'FREE'], [
        'code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
    ]);

    $path = makeLenexFixture_ri('GER');

    $service = new RecordImportService;
    $preview = $service->preview($path);

    // Athlet ist unbekannt (noch nicht in der DB) — Import bestätigt ihn wie im echten
    // Vorschau-Workflow als "neu anlegen", statt hier künstlich eine bereits passende
    // Athlete-Zeile vorzulegen.
    $athleteKey = $preview['unknown_athletes'][0]['key'];
    $result = $service->import($path, [], [$athleteKey => 'new'], [], [], [], []);

    unlink($path);

    expect($preview['records'])->toHaveCount(1)
        ->and($result['imported'])->toBe(1)
        ->and(SwimRecord::where('record_type', 'AUT')->where('sport_class', 'S6')->first()->meet_nation_id)
        ->toBe($ger->id);
});

it('lässt meet_nation_id leer, wenn MEETINFO kein nation-Attribut trägt', function () {
    Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
    StrokeType::firstOrCreate(['lenex_code' => 'FREE'], [
        'code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
    ]);

    $path = makeLenexFixture_ri('');

    $service = new RecordImportService;
    $preview = $service->preview($path);
    $athleteKey = $preview['unknown_athletes'][0]['key'];
    $service->import($path, [], [$athleteKey => 'new'], [], [], [], []);

    unlink($path);

    expect(SwimRecord::where('record_type', 'AUT')->where('sport_class', 'S6')->first()->meet_nation_id)
        ->toBeNull();
});

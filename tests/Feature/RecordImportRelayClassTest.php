<?php

use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Services\RecordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('record-import-relay-class');

// Staffeln haben nur die Staffelklasse S14/S15/S20/S21/S34/S49 — auch bei Brust- und Lagenstaffeln. SB/SM leitet der
// Rekordimport nur für Einzelstarts aus der Lage ab.

function file_rirc(string $stroke, int $relayCount, string $handicap): string
{
    $path = tempnam(sys_get_temp_dir(), 'rirc_').'.xml';
    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><LENEX version="3.0"><RECORDLISTS>'
        .'<RECORDLIST type="AUT" course="SCM" gender="X" handicap="'.$handicap.'" nation="AUT"><RECORDS>'
        .'<RECORD swimtime="00:03:16.32"><SWIMSTYLE distance="50" stroke="'.$stroke.'" relaycount="'.$relayCount.'"/>'
        .'<MEETINFO city="Prag" name="Meet" nation="CZE" date="2023-12-16"/></RECORD>'
        .'</RECORDS></RECORDLIST></RECORDLISTS></LENEX>');

    return $path;
}

beforeEach(function () {
    Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    foreach (['FREE' => 'Freistil', 'BREAST' => 'Brust', 'MEDLEY' => 'Lagen'] as $code => $name) {
        StrokeType::firstOrCreate(['lenex_code' => $code], ['name_de' => $name, 'name_en' => $name, 'code' => $code]);
    }
});

it('leitet die Sportklasse nur bei Einzelstarts aus der Lage ab', /** @throws Exception */ function (string $stroke, int $relayCount, string $handicap, string $expected) {
    $preview = (new RecordImportService)->preview(file_rirc($stroke, $relayCount, $handicap));

    expect($preview['records'])->toHaveCount(1)
        ->and($preview['records'][0]['sport_class'])->toBe($expected);
})->with([
    'Lagenstaffel' => ['MEDLEY', 4, '49', 'S49'],
    'Bruststaffel' => ['BREAST', 4, '49', 'S49'],
    'Lagenstaffel S34' => ['MEDLEY', 4, '34', 'S34'],
    'Freistilstaffel' => ['FREE', 4, '14', 'S14'],
    'Einzel Lagen' => ['MEDLEY', 1, '9', 'SM9'],
    'Einzel Brust' => ['BREAST', 1, '9', 'SB9'],
]);

it('korrigiert bestehende Staffelrekorde mit SB-/SM-Klasse auf die Staffelklasse', function () {
    $nation = Nation::where('code', 'AUT')->firstOrFail();
    $breast = StrokeType::where('lenex_code', 'BREAST')->firstOrFail();
    $base = ['stroke_type_id' => $breast->id, 'nation_id' => $nation->id, 'record_type' => 'AUT', 'gender' => 'M',
        'course' => 'SCM', 'distance' => 50, 'swim_time' => 19794, 'is_current' => true, 'record_status' => 'APPROVED'];
    $relay = SwimRecord::forceCreate([...$base, 'sport_class' => 'SB49', 'relay_count' => 4]);
    $medley = SwimRecord::forceCreate([...$base, 'sport_class' => 'SM34', 'relay_count' => 4]);
    $single = SwimRecord::forceCreate([...$base, 'sport_class' => 'SB9', 'relay_count' => 1]);

    $migration = require database_path('migrations/2026_11_12_100001_fix_relay_record_sport_classes.php');
    $migration->up();

    expect(DB::table('swim_records')->where('id', $relay->id)->value('sport_class'))->toBe('S49')
        ->and(DB::table('swim_records')->where('id', $medley->id)->value('sport_class'))->toBe('S34')
        ->and(DB::table('swim_records')->where('id', $single->id)->value('sport_class'))->toBe('SB9');
});

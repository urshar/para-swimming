<?php

use App\Models\Nation;
use App\Models\StrokeType;
use App\Services\RecordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('record-import-national-only');

// S21 ist nur national gültig (RelayClassValidator::isNationalOnlyClass): WR/ER/OR-Einträge der Klasse S21 überspringt
// der Rekordimport, ÖR der Klasse S21 und WR anderer Klassen bleiben.

function file_rinoc(string $type, string $handicap): string
{
    $path = tempnam(sys_get_temp_dir(), 'rinoc_').'.xml';
    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><LENEX version="3.0"><RECORDLISTS>'
        .'<RECORDLIST type="'.$type.'" course="SCM" gender="M" handicap="'.$handicap.'" nation="AUT"><RECORDS>'
        .'<RECORD swimtime="00:03:00.00"><SWIMSTYLE distance="50" stroke="FREE" relaycount="4"/>'
        .'<MEETINFO city="Wien" name="Meet" nation="AUT" date="2011-05-01"/></RECORD>'
        .'</RECORDS></RECORDLIST></RECORDLISTS></LENEX>');

    return $path;
}

beforeEach(function () {
    Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);
});

it('überspringt internationale Rekorde nur für die Klasse S21', /** @throws Exception */ function (string $type, string $handicap, int $expected) {
    $preview = (new RecordImportService)->preview(file_rinoc($type, $handicap));

    expect(count($preview['records']))->toBe($expected);
})->with([
    'WR S21' => ['WR', '21', 0],
    'ER S21' => ['ER', '21', 0],
    'ÖR S21' => ['AUT', '21', 1],
    'WR S14' => ['WR', '14', 1],
]);

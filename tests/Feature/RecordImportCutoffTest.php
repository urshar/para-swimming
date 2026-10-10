<?php

use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\RecordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class)->group('record-import-cutoff');

// Rekordimport mit Stichtag: Rekorde ab dem Stichtag entstehen später beim Rekord-Check der importierten Wettkämpfe
// (dann mit dem Ergebnis verknüpft) und werden aus der Liste übersprungen.

/** Ein Staffelrekord 4×$distance m Freistil SCM Herren S14; $date leer = ohne Datum. */
function record_ric(int $distance, string $date): string
{
    $meetInfo = $date === '' ? '<MEETINFO city="Wien" name="Ohne Datum" nation="AUT"/>'
        : '<MEETINFO city="Wien" name="Meet '.$date.'" nation="AUT" date="'.$date.'"/>';

    return '<RECORD swimtime="00:03:00.00"><SWIMSTYLE distance="'.$distance.'" stroke="FREE" relaycount="4"/>'
        .$meetInfo.'</RECORD>';
}

/** Einzelrekord eines unbekannten Athleten, aufgestellt am $date. */
function athleteRecord_ric(string $date): string
{
    return '<RECORD swimtime="00:00:40.00"><SWIMSTYLE distance="50" stroke="FREE" relaycount="1"/>'
        .'<ATHLETE lastname="Neu" firstname="Nina" gender="F" birthdate="2000-01-01">'
        .'<CLUB name="Unbekannter Verein" code="UNB" nation="AUT"/></ATHLETE>'
        .'<MEETINFO city="Wien" name="Meet" nation="AUT" date="'.$date.'"/></RECORD>';
}

function file_ric(string $records, string $gender = 'M'): string
{
    $path = tempnam(sys_get_temp_dir(), 'ric_').'.xml';
    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><LENEX version="3.0"><RECORDLISTS>'
        .'<RECORDLIST type="AUT" course="SCM" gender="'.$gender.'" handicap="14" nation="AUT"><RECORDS>'
        .$records.'</RECORDS></RECORDLIST></RECORDLISTS></LENEX>');

    return $path;
}

beforeEach(function () {
    Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);
});

it('überspringt in der Vorschau Rekorde ab dem Stichtag und behält ältere und solche ohne Datum', /** @throws Exception */ function () {
    $path = file_ric(record_ric(50, '2011-12-31').record_ric(100, '2012-01-01').record_ric(200, ''));

    $preview = (new RecordImportService)->preview($path, '2012-01-01');

    expect(array_column($preview['records'], 'distance'))->toBe([50, 200])
        ->and($preview['after_cutoff'])->toBe(1);
});

it('führt Athleten und Vereine übersprungener Rekorde nicht als unbekannt', /** @throws Exception */ function () {
    $preview = (new RecordImportService)->preview(file_ric(athleteRecord_ric('2015-06-01'), 'F'), '2012-01-01');

    expect($preview['records'])->toBe([])
        ->and($preview['unknown_athletes'])->toBe([])
        ->and($preview['unknown_clubs'])->toBe([]);
});

it('verhält sich ohne Stichtag wie bisher', /** @throws Exception */ function () {
    $preview = (new RecordImportService)->preview(file_ric(record_ric(50, '2011-12-31').record_ric(100, '2012-01-01')));

    expect(count($preview['records']))->toBe(2)
        ->and($preview['after_cutoff'])->toBe(0);
});

it('übergibt den Stichtag von der Vorschau an den Import', function () {
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    $path = file_ric(record_ric(50, '2011-12-31').record_ric(100, '2012-01-01').record_ric(200, ''));
    $datei = new UploadedFile($path, 'Rekorde.lxf', 'application/xml', null, true);

    $this->actingAs($admin)
        ->post(route('records.import.preview'), ['lenex_file' => $datei, 'before' => '2012-01-01'])
        ->assertOk()
        ->assertSee('Nur Rekorde vor dem 01.01.2012')
        ->assertSee('1 Eintrag');

    $this->actingAs($admin)
        ->post(route('records.import.run'))
        ->assertRedirect(route('records.index'));

    expect(SwimRecord::orderBy('distance')->pluck('distance')->all())->toBe([50, 200]);
});

it('lehnt einen ungültigen Stichtag ab', function () {
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    $datei = new UploadedFile(file_ric(record_ric(50, '2011-12-31')), 'Rekorde.lxf', 'application/xml', null, true);

    $this->actingAs($admin)
        ->post(route('records.import.preview'), ['lenex_file' => $datei, 'before' => '31.12.2011'])
        ->assertSessionHasErrors('before');
});

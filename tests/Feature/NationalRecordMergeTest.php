<?php

use App\Models\Athlete;
use App\Models\Result;
use App\Models\SwimRecord;
use App\Services\NationalRecordMergeService;
use App\Services\RecordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('national-record-merge');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

/** Einzelrekord 50 m Freistil S5 SCM; $previous wird als Vorgänger in derselben Kette verknüpft. */
function rec_nrm(string $type, int $time, string $date, Athlete $athlete, ?SwimRecord $previous, ?Result $result): SwimRecord
{
    $record = SwimRecord::create([
        'stroke_type_id' => makeStrokeType_p5()->id, 'nation_id' => makeNation_p5()->id, 'record_type' => $type,
        'sport_class' => 'S5', 'gender' => 'M', 'course' => 'SCM', 'distance' => 50, 'relay_count' => 1,
        'swim_time' => $time, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => $date,
        'athlete_id' => $athlete->id, 'club_id' => $athlete->club_id, 'supersedes_id' => $previous?->id,
        'result_id' => $result?->id,
    ]);
    $previous?->markAsSupersededBy($record);

    return $record;
}

function result_nrm(Athlete $athlete, int $time): Result
{
    $meet = makeMeet_p5();

    return Result::create(['meet_id' => $meet->id, 'swim_event_id' => makeEvent_p5($meet)->id,
        'athlete_id' => $athlete->id, 'club_id' => $athlete->club_id, 'swim_time' => $time,
        'is_national_record' => true]);
}

/** merge() deklariert Throwable; im Test führt jede Exception ohnehin zum Fehlschlag. */
function merge_nrm(bool $dryRun): array
{
    try {
        return (new NationalRecordMergeService)->merge($dryRun);
    } catch (Throwable $e) {
        throw new LogicException($e->getMessage(), 0, $e);
    }
}

/** Kette der Kategorie als [id, aktuell] in zeitlicher Reihenfolge, über supersedes_id vom aktuellen Rekord aus. */
function chain_nrm(string $type): array
{
    $chain = [];
    $record = SwimRecord::where('record_type', $type)->where('is_current', true)->sole();
    while ($record !== null) {
        array_unshift($chain, [$record->id, $record->is_current, $record->record_status]);
        $record = $record->supersedes_id !== null ? SwimRecord::find($record->supersedes_id) : null;
    }

    return $chain;
}

beforeEach(function () {
    $this->club = makeClub_p5();
});

it('benennt Kategorien nur aus dem ÖBSV-Import um und lässt ihre Kette', function () {
    $a = makeAthlete_p5($this->club);
    $old = rec_nrm('AUT.IND', 4000, '2010-05-01', $a, null, null);
    $new = rec_nrm('AUT.IND', 3900, '2015-05-01', $a, $old, null);

    merge_nrm(false);

    expect(chain_nrm('AUT'))->toBe([[$old->id, false, 'APPROVED.HISTORY'], [$new->id, true, 'APPROVED']])
        ->and(SwimRecord::where('record_type', 'AUT.IND')->count())->toBe(0);
});

it('entfernt den doppelten Import-Rekord zugunsten des Rekords aus dem Ergebnis', function () {
    $a = makeAthlete_p5($this->club);
    $import = rec_nrm('AUT.IND', 3900, '2017-04-23', $a, null, null);
    $fromResult = rec_nrm('AUT', 3900, '2017-04-22', $a, null, result_nrm($a, 3900));

    merge_nrm(false);

    expect(SwimRecord::find($import->id))->toBeNull()
        ->and(chain_nrm('AUT'))->toBe([[$fromResult->id, true, 'APPROVED']]);
});

it('entfernt Ergebnis-Rekorde, die nie Rekorde waren, und setzt ihr Rekord-Flag zurück', function () {
    $a = makeAthlete_p5($this->club);
    $official = rec_nrm('AUT.IND', 3500, '2012-01-01', $a, null, null);
    $result = result_nrm($a, 4600);
    $fake = rec_nrm('AUT', 4600, '2013-05-04', $a, null, $result); // erstes Ergebnis wurde "Rekord"

    merge_nrm(false);

    expect(SwimRecord::find($fake->id))->toBeNull()
        ->and($result->fresh()->is_national_record)->toBeFalse()
        ->and(chain_nrm('AUT'))->toBe([[$official->id, true, 'APPROVED']]);
});

it('lässt die ÖBSV-Liste bis zu ihrem Stand gelten und verknüpft spätere schnellere Ergebnisse', function () {
    $a = makeAthlete_p5($this->club);
    $b = makeAthlete_p5($this->club);
    // Liste: Stand 2019-05-19 (jüngstes Datum der Import-Rekorde)
    $official = rec_nrm('AUT.IND', 3500, '2019-05-19', $a, null, null);
    $notInList = rec_nrm('AUT', 3400, '2018-11-24', $b, null, result_nrm($b, 3400));
    $later = rec_nrm('AUT', 3300, '2023-09-16', $b, $notInList, result_nrm($b, 3300));
    $tie = rec_nrm('AUT', 3300, '2024-01-01', $a, $later, result_nrm($a, 3300)); // Gleichstand zählt nicht

    $report = merge_nrm(false);
    $reasons = collect($report['rows'])->pluck('grund', 'rekord_id');

    expect(SwimRecord::find($notInList->id))->toBeNull()
        ->and(SwimRecord::find($tie->id))->toBeNull()
        ->and(chain_nrm('AUT'))->toBe([
            [$official->id, false, 'APPROVED.HISTORY'],
            [$later->id, true, 'APPROVED'],
        ])
        ->and($report['cutoff'])->toBe('2019-05-19')
        ->and($reasons[$notInList->id])->toContain('nicht in der ÖBSV-Liste')
        ->and($reasons[$tie->id])->toContain('nicht schneller als Rekord #'.$later->id);
});

it('führt Jugendrekorde in die AUT.JR-Kette und lässt andere Kategorien unberührt', function () {
    $a = makeAthlete_p5($this->club);
    $junior = rec_nrm('AUT.IND.JG', 3800, '2015-01-01', $a, null, null);
    $regional = rec_nrm('AUT.WBSV', 3700, '2016-01-01', $a, null, null);

    merge_nrm(false);

    expect($junior->fresh()->record_type)->toBe('AUT.JR')
        ->and($regional->fresh()->record_type)->toBe('AUT.WBSV')
        ->and($regional->fresh()->is_current)->toBeTrue();
});

it('ändert im Probelauf nichts und schreibt mit dem Befehl einen Bericht', function () {
    $a = makeAthlete_p5($this->club);
    rec_nrm('AUT.IND', 3500, '2012-01-01', $a, null, null);
    rec_nrm('AUT', 4600, '2013-05-04', $a, null, null);

    $report = merge_nrm(true);

    expect($report['summary']['entfernt'])->toBe(1)
        ->and(SwimRecord::count())->toBe(2)
        ->and(SwimRecord::where('record_type', 'AUT.IND')->count())->toBe(1);

    Storage::fake('local');

    $this->artisan('records:merge-national-types', ['--dry-run' => true])
        ->expectsOutputToContain('Probelauf')
        ->expectsOutputToContain('record-merge')
        ->assertSuccessful();

    $files = Storage::disk('local')->files('record-merge');

    expect(SwimRecord::count())->toBe(2)
        ->and($files)->toHaveCount(1)
        ->and(Storage::disk('local')->get($files[0]))->toContain('Kein Rekord: nicht schneller als Rekord #');
});

it('ordnet beim Import AUT.IND und AUT.REL.JG den nationalen Typen zu', function () {
    makeStrokeType_p5();
    $xml = '<?xml version="1.0" encoding="UTF-8"?><LENEX version="3.0"><RECORDLISTS>'
        .'<RECORDLIST type="AUT.IND" course="SCM" gender="M" handicap="5"><RECORDS><RECORD swimtime="00:35.00">'
        .'<SWIMSTYLE stroke="FREE" distance="50" relaycount="1"/>'
        .'<ATHLETE lastname="Neu" firstname="Max" birthdate="2000-01-01" gender="M"><CLUB name="X" nation="AUT"/></ATHLETE>'
        .'</RECORD></RECORDS></RECORDLIST>'
        .'<RECORDLIST type="AUT.REL.JG" course="SCM" gender="M" handicap="20"><RECORDS><RECORD swimtime="02:35.00">'
        .'<SWIMSTYLE stroke="FREE" distance="50" relaycount="4"/><RELAY><CLUB name="X" nation="AUT"/></RELAY>'
        .'</RECORD></RECORDS></RECORDLIST></RECORDLISTS></LENEX>';
    $path = tempnam(sys_get_temp_dir(), 'nrm_').'.xml';
    file_put_contents($path, $xml);

    try {
        $preview = (new RecordImportService)->preview($path);
    } catch (Throwable $e) {
        throw new LogicException($e->getMessage(), 0, $e);
    }

    expect(collect($preview['pending_records'])->concat($preview['records'])->pluck('record_type')->sort()->values()->all())
        ->toBe(['AUT', 'AUT.JR']);
});

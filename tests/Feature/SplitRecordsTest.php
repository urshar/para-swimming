<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\ResultSplit;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('split-records-p2');

// Rekorde aus Zwischenzeiten von Einzelergebnissen (docs/specs/split-records.md): Standardstrecken kürzer als das
// Rennen, Freistil/Brust/Schmetterling, Zeitnahme AUTOMATIC; immer ausstehend, ohne Rekord-Flag am Ergebnis.

function base_spr(): array
{
    $austria = Nation::forceCreate(['code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    $club = Club::query()->create(['name' => 'Verein Wien', 'nation_id' => $austria->id, 'type' => 'CLUB',
        'regional_association' => 'WBSV']);
    $meet = Meet::query()->create(['name' => 'ÖSTM 2018', 'start_date' => '2018-05-05', 'end_date' => '2018-05-05',
        'course' => 'LCM', 'city' => 'Graz', 'nation_id' => $austria->id, 'timing' => 'AUTOMATIC']);

    return compact('austria', 'club', 'meet');
}

function stroke_spr(string $code): StrokeType
{
    return StrokeType::query()->firstOrCreate(['lenex_code' => $code], ['name_de' => match ($code) {
        'FREE' => 'Freistil', 'BACK' => 'Rücken', 'BREAST' => 'Brust', 'FLY' => 'Schmetterling', default => 'Lagen',
    }, 'name_en' => $code, 'code' => $code]);
}

/**
 * Ergebnis eines österreichischen Herren (S9, Jahrgang 1980) mit Zwischenzeiten [Distanz => Zeit].
 *
 * @param  array<int, int>  $splits
 */
function result_spr(array $base, string $stroke, int $distance, int $time, array $splits, ?string $status = null,
    ?string $eventTiming = null): Result
{
    static $number = 0;
    $number++;

    $event = SwimEvent::query()->create(['meet_id' => $base['meet']->id, 'stroke_type_id' => stroke_spr($stroke)->id,
        'distance' => $distance, 'relay_count' => 1, 'gender' => 'M', 'session_number' => 1, 'event_number' => $number,
        'round' => 'TIM', 'timing' => $eventTiming]);
    $athlete = Athlete::query()->create(['first_name' => 'Max', 'last_name' => 'Schwimmer'.$number, 'gender' => 'M',
        'birth_date' => '1980-01-01', 'nation_id' => $base['austria']->id, 'club_id' => $base['club']->id]);
    $result = Result::query()->create(['meet_id' => $base['meet']->id, 'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id, 'club_id' => $base['club']->id, 'sport_class' => 'S9', 'swim_time' => $time,
        'status' => $status, 'heat' => 1]);

    foreach ($splits as $splitDistance => $splitTime) {
        ResultSplit::query()->create(['result_id' => $result->id, 'distance' => $splitDistance, 'split_time' => $splitTime]);
    }

    return $result;
}

function record_spr(array $base, string $type, string $stroke, int $distance, int $time): SwimRecord
{
    return SwimRecord::query()->create(['stroke_type_id' => stroke_spr($stroke)->id, 'nation_id' => $base['austria']->id,
        'record_type' => $type, 'sport_class' => 'S9', 'gender' => 'M', 'course' => 'LCM', 'distance' => $distance,
        'relay_count' => 1, 'swim_time' => $time, 'record_status' => 'APPROVED', 'is_current' => true,
        'set_date' => '2015-01-01']);
}

/** @throws Throwable */
function check_spr(array $base): array
{
    return app(RecordCheckerService::class)->checkMeet($base['meet']);
}

/** @return list<SwimRecord> */
function splitRecords_spr(): array
{
    return SwimRecord::query()->where('from_split', true)->orderBy('record_type')->get()->all();
}

it('legt aus einer 50-m-Zwischenzeit im 100-m-Freistil-Rennen ausstehende Rekorde an', /** @throws Throwable */ function () {
    $base = base_spr();
    $old = record_spr($base, 'AUT', 'FREE', 50, 3000);
    record_spr($base, 'AUT', 'FREE', 100, 5500);
    $result = result_spr($base, 'FREE', 100, 6200, [50 => 2950]);

    $outcome = check_spr($base);
    $records = splitRecords_spr();

    expect(collect($records)->pluck('record_type')->all())->toBe(['AUT', 'AUT.WBSV'])
        ->and($records[0]->record_status)->toBe('PENDING')
        ->and($records[0]->distance)->toBe(50)
        ->and($records[0]->swim_time)->toBe(2950)
        ->and($records[0]->result_id)->toBe($result->id)
        ->and($records[0]->supersedes_id)->toBe($old->id)
        ->and($records[0]->comment)->toBe('Zwischenzeit aus 100 m Freistil')
        ->and(collect($outcome['pending_records'])->pluck('reason')->unique()->all())
        ->toBe([RecordCheckerService::PENDING_SPLIT])
        ->and($old->fresh()->is_current)->toBeTrue()
        ->and($result->fresh()->is_national_record)->toBeFalse();
});

it('prüft Endzeit und Zwischenzeit desselben Ergebnisses getrennt', /** @throws Throwable */ function () {
    $base = base_spr();
    $result = result_spr($base, 'FREE', 100, 6200, [50 => 2950]);

    check_spr($base);

    $aut = SwimRecord::query()->where('result_id', $result->id)->where('record_type', 'AUT')->orderBy('distance')->get();

    expect($aut->pluck('distance')->all())->toBe([50, 100])
        ->and($aut->pluck('record_status')->all())->toBe(['PENDING', 'APPROVED'])
        ->and($aut->pluck('from_split')->all())->toBe([true, false])
        ->and($result->fresh()->is_national_record)->toBeTrue();
});

it('legt beim erneuten Rekord-Check keinen Zwischenzeit-Rekord doppelt an', /** @throws Throwable */ function () {
    $base = base_spr();
    result_spr($base, 'FREE', 100, 6200, [50 => 2950]);

    check_spr($base);
    $outcome = check_spr($base);

    expect($outcome['pending_records'])->toBe([])
        ->and(splitRecords_spr())->toHaveCount(2);
});

it('legt je Kategorie und Wettkampf nur aus der schnellsten Zwischenzeit einen Rekord an', /** @throws Throwable */ function () {
    $base = base_spr();
    record_spr($base, 'AUT', 'FREE', 50, 3000);
    $slower = result_spr($base, 'FREE', 100, 6200, [50 => 2950]);
    $fastest = result_spr($base, 'FREE', 100, 6300, [50 => 2900]);
    // Auch eine 50-m-Zwischenzeit aus einem 200-m-Rennen zählt zur selben Kategorie.
    result_spr($base, 'FREE', 200, 13000, [50 => 2920, 100 => 6400]);

    check_spr($base);
    $aut50 = SwimRecord::query()->where('from_split', true)->where('record_type', 'AUT')->where('distance', 50)->get();

    expect($aut50)->toHaveCount(1)
        ->and($aut50->first()->result_id)->toBe($fastest->id)
        ->and($aut50->first()->swim_time)->toBe(2900)
        ->and(SwimRecord::query()->where('result_id', $slower->id)->where('from_split', true)->exists())->toBeFalse();
});

it('legt bei einem erneuten Rekord-Check keinen langsameren Zwischenzeit-Rekord desselben Wettkampfs nach', /** @throws Throwable */ function () {
    $base = base_spr();
    result_spr($base, 'FREE', 100, 6300, [50 => 2900]);
    check_spr($base);
    // Nachträglich erfasst: langsamere Zwischenzeit im selben Wettkampf.
    result_spr($base, 'FREE', 100, 6200, [50 => 2950]);

    check_spr($base);

    expect(SwimRecord::query()->where('from_split', true)->where('record_type', 'AUT')->where('distance', 50)->count())
        ->toBe(1);
});

it('wertet Rücken- und Lagen-Zwischenzeiten nie', /** @throws Throwable */ function () {
    $base = base_spr();
    result_spr($base, 'BACK', 100, 7000, [50 => 3300]);
    result_spr($base, 'MEDLEY', 200, 15000, [50 => 3300, 100 => 7000]);

    check_spr($base);

    expect(splitRecords_spr())->toBe([]);
});

it('wertet nur Standardstrecken kürzer als das Rennen', /** @throws Throwable */ function () {
    $base = base_spr();
    // Brust: 50 und 100 m zählen, 150 m keine Standardstrecke, 200 m ist die Endzeit.
    result_spr($base, 'BREAST', 200, 18000, [50 => 4000, 100 => 8500, 150 => 13000, 200 => 18000]);
    // Schmetterling: 400 m gibt es nicht als Rekordstrecke.
    result_spr($base, 'FLY', 800, 70000, [400 => 34000]);

    check_spr($base);

    $aut = collect(splitRecords_spr())->where('record_type', 'AUT');

    expect($aut->pluck('distance')->sort()->values()->all())->toBe([50, 100]);
});

it('wertet Zwischenzeiten nur bei automatischer Zeitnahme', /** @throws Throwable */ function () {
    $base = base_spr();
    $base['meet']->update(['timing' => 'SEMIAUTOMATIC']);
    result_spr($base, 'FREE', 100, 6200, [50 => 2950]);
    // Bewerb mit eigener automatischer Zeitnahme überschreibt den Wettkampf.
    result_spr($base, 'FLY', 100, 7000, [50 => 3200], null, 'AUTOMATIC');

    check_spr($base);

    expect(collect(splitRecords_spr())->pluck('comment')->unique()->all())->toBe(['Zwischenzeit aus 100 m Schmetterling']);
});

it('wertet Zwischenzeiten eines Starts außer Konkurrenz mit dem Zwischenzeit-Grund', /** @throws Throwable */ function () {
    $base = base_spr();
    result_spr($base, 'FREE', 100, 6200, [50 => 2950], 'EXH');

    $outcome = check_spr($base);
    $split = collect($outcome['pending_records'])->first(fn (array $item): bool => $item['record']->from_split);

    expect($split['reason'])->toBe(RecordCheckerService::PENDING_SPLIT)
        ->and($split['record']->record_status)->toBe('PENDING');
});

it('misst eine Zwischenzeit an einem neuen Endzeit-Rekord desselben Wettkampfs', /** @throws Throwable */ function () {
    $base = base_spr();
    // 50-m-Freistil-Endzeit 28,50 stellt den Rekord auf; die 50-m-Zwischenzeit 29,00 im 100-m-Rennen ist langsamer.
    result_spr($base, 'FREE', 50, 2850, []);
    result_spr($base, 'FREE', 100, 6200, [50 => 2900]);

    check_spr($base);

    expect(splitRecords_spr())->toBe([]);
});

it('übernimmt nur die Zwischenzeiten vor der Rekordstrecke', /** @throws Throwable */ function () {
    $base = base_spr();
    result_spr($base, 'FREE', 800, 60000, [100 => 7000, 200 => 14500, 300 => 22000, 400 => 29500, 800 => 60000]);

    check_spr($base);

    $record = SwimRecord::query()->where('from_split', true)->where('record_type', 'AUT')->where('distance', 400)->sole();

    expect($record->swim_time)->toBe(29500)
        ->and($record->splits()->orderBy('distance')->pluck('distance')->all())->toBe([100, 200, 300]);
});

it('ordnet einen bestätigten Zwischenzeit-Rekord ein, ohne das Rekord-Flag am Ergebnis zu setzen', /** @throws Throwable */ function () {
    $base = base_spr();
    $old = record_spr($base, 'AUT', 'FREE', 50, 3000);
    record_spr($base, 'AUT', 'FREE', 100, 5500);
    $result = result_spr($base, 'FREE', 100, 6200, [50 => 2950]);
    check_spr($base);

    $split = SwimRecord::query()->where('from_split', true)->where('record_type', 'AUT')->sole();
    $split->update(['record_status' => 'APPROVED']);
    $split->approve();

    expect($split->fresh()->is_current)->toBeTrue()
        ->and($old->fresh()->record_status)->toBe('APPROVED.HISTORY')
        ->and($old->fresh()->superseded_by_id)->toBe($split->id)
        ->and($result->fresh()->is_national_record)->toBeFalse();
});

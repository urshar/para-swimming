<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('record-check-best-per-meet');

// Mehrere Rekordbrüche in einer Veranstaltung: Je Rekordtyp kommt nur das beste Ergebnis in die Kette, jedes Ergebnis
// wird aber auf alle Typen (auch den Landesrekord seines Verbands) geprüft. Unbekannte Nationalität: kein Rekord.

function base_rbm(): array
{
    $austria = Nation::forceCreate(['code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    $stroke = StrokeType::query()->create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);
    $meet = Meet::query()->create(['name' => 'ÖSTM 2012', 'start_date' => '2012-05-05', 'end_date' => '2012-05-05',
        'course' => 'SCM', 'city' => 'Dornbirn', 'nation_id' => $austria->id]);
    $event = SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 100,
        'relay_count' => 1, 'gender' => 'M', 'session_number' => 1, 'event_number' => 1, 'round' => 'TIM']);

    return compact('austria', 'stroke', 'meet', 'event');
}

function club_rbm(Nation $nation, string $association): Club
{
    return Club::query()->create(['name' => 'Verein '.$association, 'nation_id' => $nation->id, 'type' => 'CLUB',
        'regional_association' => $association]);
}

function result_rbm(array $base, Club $club, string $lastName, int $time, ?Nation $nation = null, ?string $status = null): Result
{
    static $number = 0;
    $number++;

    $athlete = Athlete::query()->create(['first_name' => 'Max', 'last_name' => $lastName, 'gender' => 'M',
        'birth_date' => '1980-01-01', 'nation_id' => ($nation ?? $base['austria'])->id, 'club_id' => $club->id]);

    return Result::query()->create(['meet_id' => $base['meet']->id, 'swim_event_id' => $base['event']->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'sport_class' => 'S14', 'swim_time' => $time,
        'status' => $status, 'heat' => $number]);
}

function oldRecord_rbm(array $base, string $type, int $time): SwimRecord
{
    return SwimRecord::query()->create(['stroke_type_id' => $base['stroke']->id, 'nation_id' => $base['austria']->id,
        'record_type' => $type, 'sport_class' => 'S14', 'gender' => 'M', 'course' => 'SCM', 'distance' => 100,
        'relay_count' => 1, 'swim_time' => $time, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => '2010-01-01']);
}

/** @return list<int> Zeiten der Kette eines Rekordtyps, älteste zuerst */
function chain_rbm(string $type): array
{
    return SwimRecord::query()->where('record_type', $type)->oldest('set_date')->orderByDesc('swim_time')
        ->pluck('swim_time')->all();
}

it('trägt bei mehreren Rekordbrüchen in einer Veranstaltung nur den besten ÖR ein', /** @throws Throwable */ function () {
    $base = base_rbm();
    $club = club_rbm($base['austria'], 'WBSV');
    $old = oldRecord_rbm($base, 'AUT', 10000);
    // Der langsamere Rekordbruch steht zuerst in der Datenbank (wie Haissl vor Ryba bei der ÖSTM 2012).
    result_rbm($base, $club, 'Haissl', 9246);
    $ryba = result_rbm($base, $club, 'Ryba', 7131);

    app(RecordCheckerService::class)->checkMeet($base['meet']);

    $current = SwimRecord::query()->where('record_type', 'AUT')->where('is_current', true)->sole();

    expect(chain_rbm('AUT'))->toBe([10000, 7131])
        ->and($current->result_id)->toBe($ryba->id)
        ->and($current->supersedes_id)->toBe($old->id)
        ->and($old->fresh()->superseded_by_id)->toBe($current->id)
        ->and($ryba->fresh()->is_national_record)->toBeTrue();
});

it('prüft jedes Ergebnis auf den Landesrekord seines Verbands und trägt je Verband nur den besten ein', /** @throws Throwable */ function () {
    $base = base_rbm();
    $wien = club_rbm($base['austria'], 'WBSV');
    $tirol = club_rbm($base['austria'], 'TBSV');
    oldRecord_rbm($base, 'AUT', 10000);
    oldRecord_rbm($base, 'AUT.TBSV', 12000);
    result_rbm($base, $tirol, 'Tirol Langsam', 11500);
    $tirolFast = result_rbm($base, $tirol, 'Tirol Schnell', 9800);
    $wienFast = result_rbm($base, $wien, 'Wien', 7131);

    app(RecordCheckerService::class)->checkMeet($base['meet']);

    expect(chain_rbm('AUT'))->toBe([10000, 7131])
        ->and(chain_rbm('AUT.WBSV'))->toBe([7131])
        ->and(chain_rbm('AUT.TBSV'))->toBe([12000, 9800])
        ->and($tirolFast->fresh()->is_national_record)->toBeFalse()
        ->and($tirolFast->fresh()->is_regional_record)->toBeTrue()
        ->and($wienFast->fresh()->is_regional_record)->toBeTrue();
});

it('stellt mit unbekannter Nationalität keinen Rekord auf, auch keinen ausstehenden', /** @throws Throwable */ function () {
    $base = base_rbm();
    $club = club_rbm($base['austria'], 'WBSV');
    $unknown = Nation::forceCreate(['code' => 'XXX', 'name_de' => 'Unbekannt', 'name_en' => 'Unknown', 'is_active' => true]);
    oldRecord_rbm($base, 'AUT', 10000);
    result_rbm($base, $club, 'Unbekannt', 7000, $unknown);

    $check = app(RecordCheckerService::class)->checkMeet($base['meet']);

    expect($check['new_records'])->toBe([])
        ->and($check['pending_records'])->toBe([])
        ->and(SwimRecord::query()->count())->toBe(1);
});

it('legt einen Rekord außer Konkurrenz als ausstehend an', /** @throws Throwable */ function () {
    $base = base_rbm();
    $club = club_rbm($base['austria'], 'WBSV');
    oldRecord_rbm($base, 'AUT', 10000);
    result_rbm($base, $club, 'Ausserkonkurrenz', 7000, null, 'EXH');

    $check = app(RecordCheckerService::class)->checkMeet($base['meet']);

    expect(collect($check['pending_records'])->pluck('type')->all())->toBe(['AUT', 'AUT.WBSV'])
        ->and($check['new_records'])->toBe([])
        ->and(SwimRecord::query()->where('record_type', 'AUT')->where('record_status', 'PENDING')->count())->toBe(1);
});

<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('regional-record-manual');

// Manuell eingetragener österreichischer Rekord: Landesrekord des Vereins mit anlegen, sofern er neu ist.

function nation_rrm(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function stroke_rrm(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);
}

function club_rrm(?string $association = 'KBSV', string $code = 'VSCV'): Club
{
    return Club::query()->create(['name' => 'Verein '.$code, 'code' => $code, 'nation_id' => nation_rrm()->id,
        'regional_association' => $association]);
}

function athlete_rrm(Club $club, string $birthDate = '1990-04-01'): Athlete
{
    return Athlete::query()->create(['first_name' => 'Lena', 'last_name' => 'Muster', 'gender' => 'F',
        'birth_date' => $birthDate, 'nation_id' => nation_rrm()->id, 'club_id' => $club->id]);
}

/** Formulardaten: 50 m Freistil SCM Damen S6, österreichischer Rekord. */
function payload_rrm(array $overrides = []): array
{
    return array_merge([
        'record_type' => 'AUT', 'stroke_type_id' => stroke_rrm()->id, 'sport_class' => 'S6', 'gender' => 'F',
        'course' => 'SCM', 'distance' => 50, 'relay_count' => 1, 'swim_time' => '00:40.00',
        'record_status' => 'APPROVED', 'nation_id' => nation_rrm()->id, 'set_date' => '2011-05-14',
        'meet_name' => 'Landesmeisterschaft', 'meet_city' => 'Villach',
        'splits' => [['distance' => 25, 'split_time' => '00:19.50']],
    ], $overrides);
}

function store_rrm(array $payload): TestResponse
{
    return test()->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->post(route('records.store'), $payload);
}

/** Bestehender aktueller Rekord derselben Kategorie. */
function existing_rrm(string $type, int $time): SwimRecord
{
    return SwimRecord::query()->create(['stroke_type_id' => stroke_rrm()->id, 'nation_id' => nation_rrm()->id,
        'record_type' => $type, 'sport_class' => 'S6', 'gender' => 'F', 'course' => 'SCM', 'distance' => 50,
        'relay_count' => 1, 'swim_time' => $time, 'record_status' => 'APPROVED', 'is_current' => true,
        'set_date' => '2005-01-01']);
}

it('legt beim österreichischen Rekord den Landesrekord des Vereins mit an', function () {
    $club = club_rrm();
    $athlete = athlete_rrm($club);
    $previous = existing_rrm('AUT.KBSV', 4200);

    store_rrm(payload_rrm(['athlete_id' => $athlete->id, 'club_id' => $club->id]))
        ->assertRedirect(route('records.index'))
        ->assertSessionHas('success', 'Rekord erfolgreich eingetragen. Zusätzlich Landesrekord AUT.KBSV eingetragen.');

    $regional = SwimRecord::where('record_type', 'AUT.KBSV')->where('is_current', true)->sole();

    expect($regional->swim_time)->toBe(4000)
        ->and($regional->athlete_id)->toBe($athlete->id)
        ->and($regional->club_id)->toBe($club->id)
        ->and($regional->meet_city)->toBe('Villach')
        ->and($regional->supersedes_id)->toBe($previous->id)
        ->and($regional->splits()->pluck('split_time')->all())->toBe([1950])
        ->and($previous->fresh()->is_current)->toBeFalse()
        ->and(SwimRecord::where('record_type', 'AUT.KBSV.JR')->exists())->toBeFalse();
});

it('legt keinen Landesrekord an, wenn der bestehende schneller ist', function () {
    $club = club_rrm();
    existing_rrm('AUT.KBSV', 3900);

    store_rrm(payload_rrm(['athlete_id' => athlete_rrm($club)->id, 'club_id' => $club->id]))
        ->assertSessionHas('success', 'Rekord erfolgreich eingetragen.');

    expect(SwimRecord::where('record_type', 'AUT.KBSV')->count())->toBe(1);
});

it('legt bei Jugendlichen auch den Landes-Jugendrekord an', function () {
    $club = club_rrm();

    store_rrm(payload_rrm(['record_type' => 'AUT.JR', 'athlete_id' => athlete_rrm($club, '1995-02-02')->id,
        'club_id' => $club->id]))
        ->assertSessionHas('success', 'Rekord erfolgreich eingetragen. Zusätzlich Landesrekorde AUT.KBSV, AUT.KBSV.JR eingetragen.');
});

it('nimmt ohne Verein im Rekord den Verein des Athleten', function () {
    $club = club_rrm('TBSV', 'BSVI');

    store_rrm(payload_rrm(['athlete_id' => athlete_rrm($club)->id]));

    expect(SwimRecord::where('record_type', 'AUT.TBSV')->exists())->toBeTrue();
});

it('legt für Staffeln den Landesrekord des Staffelvereins samt Mitgliedern an', function () {
    $club = club_rrm('WBSV', 'WAT');

    store_rrm(payload_rrm([
        'distance' => 50, 'relay_count' => 4, 'gender' => 'X', 'sport_class' => 'S14', 'swim_time' => '02:30.00',
        'club_id' => $club->id, 'splits' => [],
        'relay_members' => [
            ['last_name' => 'Eins', 'first_name' => 'A', 'birth_date' => '1980-01-01'],
            ['last_name' => 'Zwei', 'first_name' => 'B', 'birth_date' => '1981-01-01'],
            ['last_name' => 'Drei', 'first_name' => 'C', 'birth_date' => '1982-01-01'],
            ['last_name' => 'Vier', 'first_name' => 'D', 'birth_date' => '1983-01-01'],
        ],
    ]));

    $regional = SwimRecord::where('record_type', 'AUT.WBSV')->sole();

    expect($regional->relayTeam()->orderBy('position')->pluck('last_name')->all())->toBe(['Eins', 'Zwei', 'Drei', 'Vier']);
});

it('legt ohne Landesverband, bei anderen Rekordtypen und bei ausstehenden Rekorden keinen Landesrekord an', function () {
    $obsv = club_rrm(null, 'OEBSV');
    $club = club_rrm();
    $athlete = athlete_rrm($club);

    store_rrm(payload_rrm(['athlete_id' => $athlete->id, 'club_id' => $obsv->id]));
    store_rrm(payload_rrm(['athlete_id' => $athlete->id, 'club_id' => $club->id, 'record_type' => 'WR', 'distance' => 100]));
    store_rrm(payload_rrm(['athlete_id' => $athlete->id, 'club_id' => $club->id, 'record_status' => 'PENDING', 'distance' => 200]));

    expect(SwimRecord::where('record_type', 'like', 'AUT.%')->exists())->toBeFalse();
});

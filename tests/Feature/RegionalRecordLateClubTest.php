<?php

use App\Models\Club;
use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('regional-record-late-club');

// Landesrekorde entstehen auch, wenn ein österreichischer Rekord seinen Verein erst nach dem Import bekommt (Bearbeiten)
// oder erst bestätigt wird; records:propagate-regional trägt fehlende für den Bestand nach.

function nation_rlc2(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function stroke_rlc2(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);
}

function club_rlc2(string $association = 'WBSV'): Club
{
    return Club::query()->create(['name' => 'Verein '.$association, 'code' => 'V'.$association,
        'nation_id' => nation_rlc2()->id, 'type' => 'CLUB', 'regional_association' => $association]);
}

/** Staffelrekord 4×50 m Freistil SCM Herren S14. */
function relayRecord_rlc2(int $time, string $date, ?Club $club, string $status = 'APPROVED', bool $current = true): SwimRecord
{
    return SwimRecord::query()->create(['stroke_type_id' => stroke_rlc2()->id, 'nation_id' => nation_rlc2()->id,
        'record_type' => 'AUT', 'sport_class' => 'S14', 'gender' => 'M', 'course' => 'SCM', 'distance' => 50,
        'relay_count' => 4, 'swim_time' => $time, 'record_status' => $status, 'is_current' => $current,
        'set_date' => $date, 'club_id' => $club?->id]);
}

function admin_rlc2(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Formulardaten zum Bearbeiten eines Rekords mit neuem Verein. */
function payload_rlc2(SwimRecord $record, ?Club $club, string $status = 'APPROVED'): array
{
    return ['record_type' => $record->record_type, 'stroke_type_id' => $record->stroke_type_id,
        'sport_class' => $record->sport_class, 'gender' => $record->gender, 'course' => $record->course,
        'distance' => $record->distance, 'relay_count' => $record->relay_count, 'swim_time' => '02:00.00',
        'record_status' => $status, 'nation_id' => $record->nation_id, 'set_date' => $record->set_date->toDateString(),
        'club_id' => $club?->id];
}

it('leitet den Landesrekord ab, wenn ein Staffel-ÖR beim Bearbeiten seinen Verein bekommt', function () {
    $record = relayRecord_rlc2(12000, '2015-05-01', null);
    $club = club_rlc2();

    $this->actingAs(admin_rlc2())->put(route('records.update', $record), payload_rlc2($record, $club))
        ->assertRedirect(route('records.show', $record))
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Landesrekord AUT.WBSV'));

    $regional = SwimRecord::query()->where('record_type', 'AUT.WBSV')->sole();

    expect($regional->club_id)->toBe($club->id)
        ->and($regional->is_current)->toBeTrue()
        ->and($regional->record_status)->toBe('APPROVED')
        ->and($regional->relay_count)->toBe(4);
});

it('leitet beim Bearbeiten ohne Vereinsänderung nichts ab', function () {
    $club = club_rlc2();
    $record = relayRecord_rlc2(12000, '2015-05-01', $club);

    $this->actingAs(admin_rlc2())->put(route('records.update', $record), payload_rlc2($record, $club));

    expect(SwimRecord::query()->where('record_type', 'AUT.WBSV')->count())->toBe(0);
});

it('leitet den Landesrekord ab, wenn ein ausstehender ÖR bestätigt wird', function () {
    $record = relayRecord_rlc2(12000, '2015-05-01', club_rlc2(), 'PENDING');

    $this->actingAs(admin_rlc2())->patch(route('records.status.update', $record), ['record_status' => 'APPROVED']);

    expect(SwimRecord::query()->where('record_type', 'AUT.WBSV')->sole()->swim_time)->toBe(12000);
});

it('trägt fehlende Landesrekorde über das Kommando chronologisch nach, ohne doppelt anzulegen', function () {
    $wien = club_rlc2();
    $older = relayRecord_rlc2(13000, '2010-05-01', $wien, 'APPROVED.HISTORY', false);
    $newer = relayRecord_rlc2(12000, '2015-05-01', $wien);
    $older->update(['superseded_by_id' => $newer->id]);
    $newer->update(['supersedes_id' => $older->id]);

    $this->artisan('records:propagate-regional', ['--dry-run' => true])
        ->expectsOutputToContain('Probelauf — 2 Landesrekord(e)')
        ->assertSuccessful();

    expect(SwimRecord::query()->where('record_type', 'AUT.WBSV')->count())->toBe(0);

    $this->artisan('records:propagate-regional')
        ->expectsConfirmation('Fehlende Landesrekorde jetzt eintragen?', 'yes')
        ->assertSuccessful();

    $chain = SwimRecord::query()->where('record_type', 'AUT.WBSV')->orderBy('set_date')->get();

    expect($chain->pluck('swim_time')->all())->toBe([13000, 12000])
        ->and($chain[0]->is_current)->toBeFalse()
        ->and($chain[0]->record_status)->toBe('APPROVED.HISTORY')
        ->and($chain[0]->superseded_by_id)->toBe($chain[1]->id)
        ->and($chain[1]->is_current)->toBeTrue()
        ->and($chain[1]->record_status)->toBe('APPROVED');

    $this->artisan('records:propagate-regional')
        ->expectsConfirmation('Fehlende Landesrekorde jetzt eintragen?', 'yes')
        ->expectsOutputToContain('Keine fehlenden Landesrekorde')
        ->assertSuccessful();
});

it('löst mit einem älteren ÖR keinen jüngeren Landesrekord ab', function () {
    $wien = club_rlc2();
    SwimRecord::query()->create(['stroke_type_id' => stroke_rlc2()->id, 'nation_id' => nation_rlc2()->id,
        'record_type' => 'AUT.WBSV', 'sport_class' => 'S14', 'gender' => 'M', 'course' => 'SCM', 'distance' => 50,
        'relay_count' => 4, 'swim_time' => 14000, 'record_status' => 'APPROVED', 'is_current' => true,
        'set_date' => '2018-01-01', 'club_id' => $wien->id]);
    relayRecord_rlc2(12000, '2015-05-01', $wien);

    $this->artisan('records:propagate-regional')
        ->expectsConfirmation('Fehlende Landesrekorde jetzt eintragen?', 'yes')
        ->assertSuccessful();

    expect(SwimRecord::query()->where('record_type', 'AUT.WBSV')->count())->toBe(1);
});

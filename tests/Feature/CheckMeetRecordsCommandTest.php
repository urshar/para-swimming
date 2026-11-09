<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('records-check-meets');

// Rekord-Check aller Wettkämpfe nach Datum (records:check-meets), z. B. zum Neuaufbau nach einem Basis-Import.

function nation_cmr(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

/** Wettkampf mit einem 100-m-Freistil-Ergebnis (S9, Herren, Langbahn), ohne Zwischenzeiten. */
function meet_cmr(string $date, int $swimTime): Meet
{
    $austria = nation_cmr();
    $stroke = StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);
    $club = Club::query()->create(['name' => 'Verein '.$date, 'nation_id' => $austria->id, 'type' => 'CLUB']);
    $meet = Meet::query()->create(['name' => 'Meet '.$date, 'start_date' => $date, 'end_date' => $date, 'course' => 'LCM',
        'city' => 'Wien', 'nation_id' => $austria->id, 'timing' => 'AUTOMATIC']);
    $event = SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 100,
        'relay_count' => 1, 'gender' => 'M', 'session_number' => 1, 'event_number' => 1, 'round' => 'TIM']);
    $athlete = Athlete::query()->create(['first_name' => 'Max', 'last_name' => 'Muster '.$date, 'gender' => 'M',
        'birth_date' => '1980-01-01', 'nation_id' => $austria->id, 'club_id' => $club->id]);
    Result::query()->create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'sport_class' => 'S9', 'swim_time' => $swimTime, 'heat' => 1]);

    return $meet;
}

it('zeigt im Probelauf die Ergebnisse an, ohne etwas zu speichern', function () {
    meet_cmr('2018-05-05', 7000);

    $this->artisan('records:check-meets', ['--dry-run' => true])
        ->expectsOutputToContain('Meet 2018-05-05')
        ->expectsOutputToContain('Probelauf — 1 Wettkampf/Wettkämpfe, 1 Ergebnis(se) geprüft: 1 neue(r) Rekord(e)')
        ->assertSuccessful();

    expect(SwimRecord::query()->count())->toBe(0)
        ->and(Result::query()->sole()->is_national_record)->toBeFalse();
});

it('legt nach Bestätigung die Rekorde an und setzt die Flags am Ergebnis', function () {
    meet_cmr('2018-05-05', 7000);

    $this->artisan('records:check-meets')
        ->expectsConfirmation('1 Wettkampf/Wettkämpfe jetzt auf Rekorde prüfen?', 'yes')
        ->assertSuccessful();

    $record = SwimRecord::query()->where('record_type', 'AUT')->sole();

    expect($record->swim_time)->toBe(7000)
        ->and($record->is_current)->toBeTrue()
        ->and($record->result_id)->toBe(Result::query()->sole()->id)
        ->and(Result::query()->sole()->is_national_record)->toBeTrue();
});

it('prüft die Wettkämpfe nach Datum, nicht nach Anlage-Reihenfolge', function () {
    // Zuerst angelegt, aber später geschwommen und schneller.
    meet_cmr('2019-05-05', 6900);
    meet_cmr('2017-05-05', 7000);

    $this->artisan('records:check-meets')
        ->expectsConfirmation('2 Wettkampf/Wettkämpfe jetzt auf Rekorde prüfen?', 'yes')
        ->assertSuccessful();

    $chain = SwimRecord::query()->where('record_type', 'AUT')->orderBy('set_date')->get();

    expect($chain)->toHaveCount(2)
        ->and($chain[0]->swim_time)->toBe(7000)
        ->and($chain[0]->is_current)->toBeFalse()
        ->and($chain[1]->swim_time)->toBe(6900)
        ->and($chain[1]->is_current)->toBeTrue();
});

it('legt bei einem zweiten Lauf nichts doppelt an', function () {
    meet_cmr('2018-05-05', 7000);

    $this->artisan('records:check-meets')->expectsConfirmation('1 Wettkampf/Wettkämpfe jetzt auf Rekorde prüfen?', 'yes');
    $count = SwimRecord::query()->count();

    $this->artisan('records:check-meets', ['--dry-run' => true])
        ->expectsOutputToContain('0 neue(r) Rekord(e)')
        ->assertSuccessful();

    expect(SwimRecord::query()->count())->toBe($count);
});

it('prüft mit --meet nur einen Wettkampf und meldet einen unbekannten', function () {
    $later = meet_cmr('2019-05-05', 6900);
    meet_cmr('2017-05-05', 7000);

    $this->artisan('records:check-meets', ['--dry-run' => true, '--meet' => $later->id])
        ->expectsOutputToContain('Meet 2019-05-05')
        ->doesntExpectOutputToContain('Meet 2017-05-05')
        ->assertSuccessful();

    $this->artisan('records:check-meets', ['--dry-run' => true, '--meet' => 999])
        ->expectsOutputToContain('Wettkampf 999 nicht gefunden.')
        ->assertFailed();
});

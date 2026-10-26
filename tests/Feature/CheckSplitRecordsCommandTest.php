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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('split-records-p5');

// Nachprüfung der Zwischenzeiten bereits geprüfter Wettkämpfe (docs/specs/split-records.md, Phase 5).

function nation_csr(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

/** Wettkampf mit einem 100-m-Freistil-Ergebnis (S9, Herren) und einer 50-m-Zwischenzeit. */
function meet_csr(string $date, int $splitTime): Meet
{
    $austria = nation_csr();
    $stroke = StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'code' => 'FREE']);
    $club = Club::query()->create(['name' => 'Verein '.$date, 'nation_id' => $austria->id, 'type' => 'CLUB']);
    $meet = Meet::query()->create(['name' => 'Meet '.$date, 'start_date' => $date, 'end_date' => $date, 'course' => 'SCM',
        'city' => 'Wien', 'nation_id' => $austria->id, 'timing' => 'AUTOMATIC']);
    $event = SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 100,
        'relay_count' => 1, 'gender' => 'M', 'session_number' => 1, 'event_number' => 1, 'round' => 'TIM']);
    $athlete = Athlete::query()->create(['first_name' => 'Max', 'last_name' => 'Muster '.$date, 'gender' => 'M',
        'birth_date' => '1980-01-01', 'nation_id' => $austria->id, 'club_id' => $club->id]);
    $result = Result::query()->create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'sport_class' => 'S9', 'swim_time' => 7000, 'heat' => 1]);
    ResultSplit::query()->create(['result_id' => $result->id, 'distance' => 50, 'split_time' => $splitTime]);

    return $meet;
}

it('zeigt im Probelauf die Zwischenzeit-Rekorde an, ohne etwas zu speichern', function () {
    meet_csr('2018-05-05', 2950);

    $this->artisan('records:check-splits', ['--dry-run' => true])
        ->expectsOutputToContain('Zwischenzeit aus 100 m Freistil')
        ->expectsOutputToContain('Probelauf — 1 ausstehende(r) Rekord(e)')
        ->assertSuccessful();

    expect(SwimRecord::query()->count())->toBe(0);
});

it('legt nach Bestätigung nur Zwischenzeit-Rekorde an, keine aus Endzeiten', function () {
    meet_csr('2018-05-05', 2950);

    $this->artisan('records:check-splits')
        ->expectsConfirmation('Zwischenzeiten von 1 Wettkampf/Wettkämpfen jetzt prüfen?', 'yes')
        ->assertSuccessful();

    $record = SwimRecord::query()->sole();

    expect($record->from_split)->toBeTrue()
        ->and($record->distance)->toBe(50)
        ->and($record->record_status)->toBe('PENDING');
});

it('prüft alle Wettkämpfe und mit --meet nur einen', function () {
    $later = meet_csr('2019-05-05', 2900);
    meet_csr('2017-05-05', 2950);

    $this->artisan('records:check-splits', ['--dry-run' => true])
        ->expectsOutputToContain('05.05.2017')
        ->assertSuccessful();

    $this->artisan('records:check-splits', ['--dry-run' => true, '--meet' => $later->id])
        ->expectsOutputToContain('Probelauf — 1 ausstehende(r) Rekord(e)')
        ->doesntExpectOutputToContain('05.05.2017')
        ->assertSuccessful();
});

it('legt bei einem zweiten Lauf nichts doppelt an', function () {
    meet_csr('2018-05-05', 2950);

    $this->artisan('records:check-splits')->expectsConfirmation('Zwischenzeiten von 1 Wettkampf/Wettkämpfen jetzt prüfen?', 'yes');
    $this->artisan('records:check-splits', ['--dry-run' => true])
        ->expectsOutputToContain('Keine neuen Rekorde aus Zwischenzeiten')
        ->assertSuccessful();

    expect(SwimRecord::query()->count())->toBe(1);
});

it('meldet einen unbekannten Wettkampf', function () {
    $this->artisan('records:check-splits', ['--dry-run' => true, '--meet' => 999])
        ->expectsOutputToContain('Wettkampf 999 nicht gefunden.')
        ->assertFailed();
});

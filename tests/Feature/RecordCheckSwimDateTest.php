<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\MeetSession;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('record-check-swim-date');

// Rekorddatum = Wettkampftag (Datum des Abschnitts aus meet_sessions), ohne gepflegtes Datum der
// Veranstaltungsbeginn. Maßstab bleibt der Rekord bei Veranstaltungsbeginn; je Kategorie und Veranstaltung stellt
// nur das schnellste Ergebnis den Rekord auf, auch über mehrere Tage (Entscheidung Erik, 12.11.2026).

/** @return array{austria: Nation, stroke: StrokeType, meet: Meet, club: Club, day1: SwimEvent, day3: SwimEvent} */
function base_rsd(bool $withSessions = true): array
{
    $austria = Nation::forceCreate(['code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    $stroke = StrokeType::query()->create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);
    $meet = Meet::query()->create(['name' => 'ÖSTM 2026', 'start_date' => '2026-09-07', 'end_date' => '2026-09-12',
        'course' => 'LCM', 'city' => 'Linz', 'nation_id' => $austria->id]);
    $club = Club::query()->create(['name' => 'Verein WBSV', 'nation_id' => $austria->id, 'type' => 'CLUB',
        'regional_association' => 'WBSV']);

    $events = [];
    foreach (['day1' => [1, '2026-09-07'], 'day3' => [3, '2026-09-09']] as $key => [$session, $date]) {
        if ($withSessions) {
            MeetSession::query()->create(['meet_id' => $meet->id, 'number' => $session, 'date' => $date]);
        }
        $events[$key] = SwimEvent::query()->create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id,
            'distance' => 100, 'relay_count' => 1, 'gender' => 'M', 'session_number' => $session,
            'event_number' => $session, 'round' => 'TIM']);
    }

    return ['austria' => $austria, 'stroke' => $stroke, 'meet' => $meet, 'club' => $club, ...$events];
}

function result_rsd(array $base, SwimEvent $event, string $lastName, int $time): Result
{
    $athlete = Athlete::query()->create(['first_name' => 'Max', 'last_name' => $lastName, 'gender' => 'M',
        'birth_date' => '1980-01-01', 'nation_id' => $base['austria']->id, 'club_id' => $base['club']->id]);

    return Result::query()->create(['meet_id' => $base['meet']->id, 'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id, 'club_id' => $base['club']->id, 'sport_class' => 'S14', 'swim_time' => $time]);
}

function oldRecord_rsd(array $base, int $time, string $date = '2020-01-01'): SwimRecord
{
    return SwimRecord::query()->create(['stroke_type_id' => $base['stroke']->id, 'nation_id' => $base['austria']->id,
        'record_type' => 'AUT', 'sport_class' => 'S14', 'gender' => 'M', 'course' => 'LCM', 'distance' => 100,
        'relay_count' => 1, 'swim_time' => $time, 'record_status' => 'APPROVED', 'is_current' => true,
        'set_date' => $date]);
}

it('speichert als Rekorddatum den Tag des Abschnitts', /** @throws Throwable */ function () {
    $base = base_rsd();
    oldRecord_rsd($base, 10000);
    $result = result_rsd($base, $base['day3'], 'Dritter', 9000);

    app(RecordCheckerService::class)->checkMeet($base['meet']);

    $record = SwimRecord::query()->where('record_type', 'AUT')->where('result_id', $result->id)->sole();

    expect($record->set_date->toDateString())->toBe('2026-09-09')
        ->and($record->is_current)->toBeTrue()
        ->and($record->record_status)->toBe('APPROVED');
});

it('nimmt ohne Abschnittsdatum den Veranstaltungsbeginn', /** @throws Throwable */ function () {
    $base = base_rsd(false);
    oldRecord_rsd($base, 10000);
    $result = result_rsd($base, $base['day3'], 'Dritter', 9000);

    app(RecordCheckerService::class)->checkMeet($base['meet']);

    expect(SwimRecord::query()->where('result_id', $result->id)->where('record_type', 'AUT')->sole()
        ->set_date->toDateString())->toBe('2026-09-07');
});

it('trägt über mehrere Tage nur das schnellste Ergebnis der Veranstaltung ein', /** @throws Throwable */ function (string $fastDay, string $slowDay) {
    $base = base_rsd();
    $old = oldRecord_rsd($base, 10000);
    result_rsd($base, $base[$slowDay], 'Langsam', 9500);
    $fast = result_rsd($base, $base[$fastDay], 'Schnell', 9000);

    app(RecordCheckerService::class)->checkMeet($base['meet']);

    $records = SwimRecord::query()->where('record_type', 'AUT')->whereKeyNot($old->id)->get();

    expect($records)->toHaveCount(1)
        ->and($records->first()->result_id)->toBe($fast->id)
        ->and($records->first()->supersedes_id)->toBe($old->id)
        ->and($old->fresh()->record_status)->toBe('APPROVED.HISTORY');
})->with([
    'schnellstes am 3. Tag' => ['day3', 'day1'],
    'schnellstes am 1. Tag' => ['day1', 'day3'],
]);

it('zieht mit records:fix-set-dates das Datum bestehender Rekorde auf den Wettkampftag nach', function () {
    $base = base_rsd();
    $result = result_rsd($base, $base['day3'], 'Dritter', 9000);
    $fromCheck = oldRecord_rsd($base, 9000, '2026-09-07');
    $fromCheck->update(['result_id' => $result->id]);
    // Schon korrekt, importiert ohne Ergebnis, und von Hand auf ein anderes Datum gesetzt: bleiben unverändert.
    $dayOne = oldRecord_rsd($base, 9500, '2026-09-07');
    $dayOne->update(['result_id' => result_rsd($base, $base['day1'], 'Erster', 9500)->id]);
    $imported = oldRecord_rsd($base, 9800, '2026-09-07');
    $manual = oldRecord_rsd($base, 9700, '2026-09-10');
    $manual->update(['result_id' => result_rsd($base, $base['day3'], 'Hand', 9700)->id]);

    $this->artisan('records:fix-set-dates', ['--dry-run' => true])
        ->expectsOutputToContain('1 Rekord(e) würden geändert')
        ->assertSuccessful();

    expect($fromCheck->fresh()->set_date->toDateString())->toBe('2026-09-07');

    $this->artisan('records:fix-set-dates')
        ->expectsConfirmation('1 Rekorddatum/-daten jetzt auf den Wettkampftag setzen?', 'yes')
        ->assertSuccessful();

    expect($fromCheck->fresh()->set_date->toDateString())->toBe('2026-09-09')
        ->and($dayOne->fresh()->set_date->toDateString())->toBe('2026-09-07')
        ->and($imported->fresh()->set_date->toDateString())->toBe('2026-09-07')
        ->and($manual->fresh()->set_date->toDateString())->toBe('2026-09-10');

    $this->artisan('records:fix-set-dates')
        ->expectsOutput('Keine Rekorde zu ändern.')
        ->assertSuccessful();
});

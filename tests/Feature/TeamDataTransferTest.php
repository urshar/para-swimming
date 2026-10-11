<?php

use App\Models\Athlete;
use App\Models\AthleteClassification;
use App\Models\AthleteClubHistory;
use App\Models\AthleteLevelHistory;
use App\Models\Classifier;
use App\Models\Club;
use App\Models\ExceptionCode;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Services\TeamDataTransferService;
use Database\Seeders\TeamDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('team-data-transfer');

// Umzug Dev → Prod: Export als JSON, Einspielen in eine leere Datenbank mit Umschlüsselung der Stammdaten über den
// Code. Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

/** Tabellen des Exports in umgekehrter Einfügereihenfolge (zum Leeren). */
const TABLES_TDT = ['relay_team_members', 'record_splits', 'swim_records', 'athlete_classification_exceptions',
    'athlete_classifications', 'athlete_exceptions', 'athlete_club_history', 'athlete_level_history',
    'athlete_sport_classes', 'athletes', 'clubs', 'classifiers'];

function directory_tdt(): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'team_tdt_'.uniqid();
    mkdir($directory);

    return $directory;
}

/** Ein Satz Team-Daten mit allen Verknüpfungen; liefert die Rekorde der Kette (alt, neu). */
function seedTeamData_tdt(): array
{
    $austria = Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    $stroke = StrokeType::query()->create(['code' => 'free', 'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']);
    $exception = ExceptionCode::query()->create(['code' => 'A', 'name_de' => 'A', 'name_en' => 'A', 'is_active' => true]);

    $classifier = Classifier::query()->create(['first_name' => 'Hartwig', 'last_name' => 'Maurer', 'type' => 'MED', 'nation_id' => $austria->id]);
    $club = Club::query()->create(['name' => 'VCA Salzburg', 'short_name' => 'VCA', 'code' => 'VCAS', 'nation_id' => $austria->id, 'type' => 'CLUB']);
    $athlete = Athlete::query()->create(['first_name' => 'Carina', 'last_name' => 'Muster', 'gender' => 'F', 'nation_id' => $austria->id,
        'club_id' => $club->id, 'license' => 'S-0726', 'birth_date' => '2001-01-20', 'level' => 'A', 'disability_group' => 'PI']);
    $athlete->sportClasses()->create(['category' => 'S', 'class_number' => '3', 'sport_class' => 'S3', 'classification_status' => 'CONFIRMED']);
    $athlete->exceptions()->attach($exception->id, ['category' => null, 'note' => 'Notiz']);
    AthleteLevelHistory::query()->create(['athlete_id' => $athlete->id, 'user_id' => null, 'level' => 'A', 'changed_at' => '2026-10-09']);
    AthleteClubHistory::query()->create(['athlete_id' => $athlete->id, 'club_id' => $club->id, 'joined_at' => '2015-01-01', 'is_active' => true]);
    $classification = AthleteClassification::query()->create(['athlete_id' => $athlete->id, 'med_classifier_id' => $classifier->id,
        'classified_at' => '2026-05-06', 'location' => 'Berlin', 'result_s' => 'S3', 'classification_scope' => 'INTL',
        'classification_status' => 'CONFIRMED']);
    $classification->exceptions()->attach($exception->id, ['category' => 'S', 'note' => null]);

    $attributes = ['stroke_type_id' => $stroke->id, 'nation_id' => $austria->id, 'record_type' => 'AUT', 'sport_class' => 'S3',
        'gender' => 'F', 'course' => 'LCM', 'distance' => 50, 'relay_count' => 1, 'record_status' => 'APPROVED',
        'athlete_id' => $athlete->id, 'club_id' => $club->id];
    $older = SwimRecord::query()->create($attributes + ['swim_time' => 4500, 'set_date' => '2010-01-01', 'is_current' => true]);
    $newer = SwimRecord::query()->create($attributes + ['swim_time' => 4400, 'set_date' => '2012-01-01', 'is_current' => true,
        'supersedes_id' => $older->id]);
    $older->markAsSupersededBy($newer);
    $newer->splits()->create(['distance' => 25, 'split_time' => 2100]);

    return [$older, $newer];
}

/** Inhalt aller Export-Tabellen, ohne Zeitstempel. */
function snapshot_tdt(): array
{
    $snapshot = [];
    foreach (TABLES_TDT as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()
            ->map(fn (object $row) => array_diff_key((array) $row, ['created_at' => 0, 'updated_at' => 0]))->all();
    }

    return $snapshot;
}

function wipe_tdt(): void
{
    foreach (TABLES_TDT as $table) {
        DB::table($table)->delete();
    }
}

it('überträgt alle Team-Daten samt Verknüpfungen in eine leere Datenbank', /** @throws Throwable */ function () {
    [$older, $newer] = seedTeamData_tdt();
    $directory = directory_tdt();
    $before = snapshot_tdt();

    $counts = app(TeamDataTransferService::class)->export($directory);
    wipe_tdt();
    app(TeamDataTransferService::class)->import($directory);

    expect($counts['athletes'])->toBe(1)
        ->and($counts['swim_records'])->toBe(2)
        ->and(snapshot_tdt())->toEqual($before)
        ->and(SwimRecord::query()->findOrFail($older->id)->superseded_by_id)->toBe($newer->id)
        ->and(SwimRecord::query()->findOrFail($newer->id)->supersedes_id)->toBe($older->id);
});

it('schlüsselt Nationen, Ausnahme-Codes und Lagen über den Code um', /** @throws Throwable */ function () {
    seedTeamData_tdt();
    $directory = directory_tdt();
    app(TeamDataTransferService::class)->export($directory);
    wipe_tdt();

    // Auf dem Ziel haben die Stammdaten andere IDs.
    DB::table('athlete_exceptions')->delete();
    ExceptionCode::query()->delete();
    StrokeType::query()->delete();
    Nation::query()->delete();
    Nation::query()->create(['code' => 'GER', 'name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);
    $austria = Nation::query()->create(['code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
    ExceptionCode::query()->create(['code' => 'B', 'name_de' => 'B', 'name_en' => 'B', 'is_active' => true]);
    $exception = ExceptionCode::query()->create(['code' => 'A', 'name_de' => 'A', 'name_en' => 'A', 'is_active' => true]);
    StrokeType::query()->create(['code' => 'back', 'lenex_code' => 'BACK', 'name_de' => 'Rücken', 'name_en' => 'Backstroke']);
    $stroke = StrokeType::query()->create(['code' => 'free', 'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']);

    app(TeamDataTransferService::class)->import($directory);

    $athlete = Athlete::query()->sole();

    expect($athlete->nation_id)->toBe($austria->id)
        ->and(Club::query()->sole()->nation_id)->toBe($austria->id)
        ->and($athlete->exceptions()->sole()->id)->toBe($exception->id)
        ->and(AthleteClassification::query()->sole()->exceptions()->sole()->id)->toBe($exception->id)
        ->and(SwimRecord::query()->pluck('stroke_type_id')->unique()->all())->toBe([$stroke->id]);
});

it('bricht den Import ab, wenn auf dem Ziel Stammdaten fehlen, und schreibt dann nichts', /** @throws Throwable */ function () {
    seedTeamData_tdt();
    $directory = directory_tdt();
    app(TeamDataTransferService::class)->export($directory);
    wipe_tdt();
    DB::table('athlete_exceptions')->delete();
    ExceptionCode::query()->delete();

    expect(fn () => app(TeamDataTransferService::class)->import($directory))
        ->toThrow(RuntimeException::class, 'exception_codes: A')
        ->and(Athlete::query()->count())->toBe(0);
});

it('verweigert den Import in eine Datenbank mit Vereinen oder Athleten', /** @throws Throwable */ function () {
    seedTeamData_tdt();
    $directory = directory_tdt();
    app(TeamDataTransferService::class)->export($directory);

    expect(fn () => app(TeamDataTransferService::class)->import($directory))
        ->toThrow(RuntimeException::class, 'bereits Vereine oder Athleten')
        ->and(Athlete::query()->count())->toBe(1);
});

it('verweigert den Export, solange Rekorde oder Klassen-Hinweise an Wettkämpfen hängen', /** @throws Throwable */ function () {
    [$older] = seedTeamData_tdt();
    $meet = makeMeet_p5();
    $athlete = Athlete::query()->sole();
    $event = makeEvent_p5($meet);
    $result = Result::query()->create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id, 'sport_class' => 'S3', 'swim_time' => 4400]);
    $older->update(['result_id' => $result->id]);
    $athlete->classifications()->create(['meet_id' => $meet->id, 'result_s' => 'S4', 'classification_scope' => 'NAT']);
    $directory = directory_tdt();

    expect(fn () => app(TeamDataTransferService::class)->export($directory))
        ->toThrow(RuntimeException::class, '1 Rekord(e) stammen aus Wettkampfergebnissen')
        ->and(app(TeamDataTransferService::class)->exportBlockers())->toHaveCount(2)
        ->and(app(TeamDataTransferService::class)->hasExport($directory))->toBeFalse();
});

it('spielt über den Seeder ein und meldet einen fehlenden Export, ohne etwas zu tun', function () {
    $this->artisan('db:seed', ['--class' => TeamDataSeeder::class])
        ->expectsOutputToContain('Kein Export')
        ->assertSuccessful();

    expect(Athlete::query()->count())->toBe(0);
})->skip(
    fn () => app(TeamDataTransferService::class)->hasExport(TeamDataTransferService::defaultDirectory()),
    'Im Standardordner liegt ein echter Export.',
);

it('exportiert über das Kommando und nennt die Zeilen je Tabelle', function () {
    seedTeamData_tdt();
    $directory = directory_tdt();

    $this->artisan('team-data:export', ['--path' => $directory])
        ->expectsOutputToContain('Export nach')
        ->assertSuccessful();

    expect(app(TeamDataTransferService::class)->hasExport($directory))->toBeTrue();
});

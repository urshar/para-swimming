<?php

use App\Models\SwimRecord;
use App\Models\User;
use App\Services\DatabaseSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses()->group('database-snapshot');

// Kompletter Datenbestand Dev → Prod: db:export-seed + ProductionDataSeeder (DatabaseSnapshotService).
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function directory_dbs(): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dbs_'.uniqid();
    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

    return $directory;
}

/** @return list<string> */
function tables_dbs(): array
{
    return array_values(array_diff(Schema::getTableListing(Schema::getCurrentSchemaName(), false),
        DatabaseSnapshotService::EXCLUDED_TABLES));
}

/** @return array<string, list<array<string, mixed>>> */
function dump_dbs(): array
{
    $dump = [];
    foreach (tables_dbs() as $table) {
        $query = DB::table($table);
        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');
        }
        $dump[$table] = $query->get()->map(fn (object $row) => (array) $row)->all();
    }

    return $dump;
}

/** Leert alle Tabellen in umgekehrter Einfügereihenfolge (Fremdschlüssel bleiben aktiv). */
function wipe_dbs(): void
{
    $plan = app(DatabaseSnapshotService::class)->insertionPlan(tables_dbs());
    foreach ($plan as $table => $deferred) {
        if ($deferred !== []) {
            DB::table($table)->update(array_fill_keys($deferred, null));
        }
    }
    foreach (array_reverse(array_keys($plan)) as $table) {
        DB::table($table)->delete();
    }
}

/** Datenbestand mit Vorwärts- und Selbstverweisen (Benutzer → Verein, Rekord-Kette). */
function seed_dbs(): void
{
    $club = makeClub_p5();
    makeAthlete_p5($club);
    User::forceCreate(['name' => 'Verein', 'email' => 'verein_dbs@example.test', 'password' => bcrypt('secret'),
        'club_id' => $club->id]);

    $base = ['stroke_type_id' => makeStrokeType_p5()->id, 'record_type' => 'AUT', 'sport_class' => 'S9',
        'gender' => 'M', 'course' => 'LCM', 'distance' => 100, 'relay_count' => 1, 'record_status' => 'APPROVED'];
    $old = SwimRecord::forceCreate([...$base, 'swim_time' => 6100, 'is_current' => false]);
    $new = SwimRecord::forceCreate([...$base, 'swim_time' => 6000, 'is_current' => true, 'supersedes_id' => $old->id]);
    $old->update(['superseded_by_id' => $new->id]);

    DB::table('cache')->insert(['key' => 'x', 'value' => 'y', 'expiration' => 0]);
}

it('spielt den exportierten Datenbestand unverändert mit den Original-IDs wieder ein', /** @throws Throwable */ function () {
    seed_dbs();
    $before = dump_dbs();
    $directory = directory_dbs();
    $service = app(DatabaseSnapshotService::class);

    $counts = $service->export($directory);
    wipe_dbs();
    $imported = $service->import($directory);

    expect(dump_dbs())->toBe($before)
        ->and($imported)->toEqual($counts)
        ->and($counts['swim_records'])->toBe(2)
        ->and($counts['users'])->toBe(1)
        ->and(SwimRecord::where('swim_time', 6100)->value('superseded_by_id'))
        ->toBe(SwimRecord::where('swim_time', 6000)->value('id'));
});

it('exportiert keine Laufzeitdaten des Frameworks', /** @throws JsonException */ function () {
    seed_dbs();
    $directory = directory_dbs();

    $counts = app(DatabaseSnapshotService::class)->export($directory);

    expect($counts)->not->toHaveKeys(DatabaseSnapshotService::EXCLUDED_TABLES)
        ->and(File::exists("$directory/cache.ndjson"))->toBeFalse()
        ->and(File::exists("$directory/migrations.ndjson"))->toBeFalse()
        ->and(File::exists("$directory/athletes.ndjson"))->toBeTrue();
});

it('setzt Selbstverweise und Vorwärtsverweise erst nach dem Einfügen', function () {
    $plan = app(DatabaseSnapshotService::class)->insertionPlan(tables_dbs());
    $order = array_keys($plan);

    expect($plan['swim_records'])->toContain('superseded_by_id', 'supersedes_id')
        ->and(array_search('users', $order))->toBeGreaterThan(array_search('clubs', $order))
        ->and(array_search('athletes', $order))->toBeGreaterThan(array_search('clubs', $order))
        ->and(array_search('results', $order))->toBeGreaterThan(array_search('swim_events', $order))
        ->and($plan['users'])->toBe([]);
});

it('verweigert den Import in eine Datenbank mit vorhandenen Daten', /** @throws JsonException */ function () {
    seed_dbs();
    $directory = directory_dbs();
    $service = app(DatabaseSnapshotService::class);
    $service->export($directory);

    expect($service->importBlockers($directory))->toContain('Tabelle athletes ist nicht leer.')
        ->and(fn () => $service->import($directory))->toThrow(RuntimeException::class, 'Tabelle athletes ist nicht leer.');
});

it('verweigert den Import, wenn das Schema nicht zum Export passt', /** @throws JsonException */ function () {
    $directory = directory_dbs();
    $service = app(DatabaseSnapshotService::class);
    $service->export($directory);

    $meta = json_decode(File::get("$directory/_meta.json"), true);
    $meta['tables']['clubs']['columns'][] = 'gibt_es_nicht';
    $meta['tables']['alte_tabelle'] = ['columns' => ['id'], 'rows' => 0];
    unset($meta['tables']['coaches']);
    File::put("$directory/_meta.json", json_encode($meta));

    expect($service->importBlockers($directory))->toBe([
        'Tabelle alte_tabelle fehlt in der Zieldatenbank.',
        'Tabelle coaches fehlt im Export.',
        'Die Spalten von clubs stimmen nicht mit dem Export überein.',
    ]);
});

it('schreibt den Export über den Befehl db:export-seed', function () {
    seed_dbs();
    $directory = directory_dbs();

    $this->artisan('db:export-seed', ['--path' => $directory])
        ->expectsOutputToContain('Zeilen nach')
        ->assertSuccessful();

    expect(File::exists("$directory/_meta.json"))->toBeTrue()
        ->and(count(File::lines("$directory/swim_records.ndjson")->filter()->all()))->toBe(2);
});

it('spielt den Export über den ProductionDataSeeder ein und meldet einen fehlenden Export', function () {
    $this->artisan('db:seed', ['--class' => 'ProductionDataSeeder'])
        ->expectsOutputToContain('Kein Export')
        ->assertSuccessful();
})->skip(fn () => File::exists(DatabaseSnapshotService::defaultDirectory().'/_meta.json'),
    'Lokaler Export vorhanden — der Seeder würde ihn einspielen wollen.');

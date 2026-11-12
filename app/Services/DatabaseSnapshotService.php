<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use SplFileObject;
use Throwable;

/**
 * Überträgt den kompletten Datenbestand (alle Tabellen außer Framework-Laufzeitdaten) von einer Datenbank in eine
 * leere andere, z. B. für den Go-live Dev → Prod: export() schreibt je Tabelle eine NDJSON-Datei (eine Zeile pro
 * Datensatz) plus _meta.json, import() (über den ProductionDataSeeder) spielt sie mit den Original-IDs ein.
 *
 * Die Einfügereihenfolge folgt den Fremdschlüsseln. Verweise auf die eigene Tabelle (z. B. die Rekord-Kette
 * superseded_by_id) oder auf eine noch nicht eingespielte Tabelle werden erst eingefügt, wenn alle Zeilen da sind —
 * so funktioniert der Import auch mit aktiven Fremdschlüssel-Prüfungen und auf SQLite.
 *
 * Die Dateien enthalten Personen- und Gesundheitsdaten sowie Passwort-Hashes: Sie liegen in einem von Git
 * ausgeschlossenen Ordner und werden auf sicherem Weg auf den Zielserver kopiert und danach gelöscht.
 */
final readonly class DatabaseSnapshotService
{
    /** Laufzeitdaten des Frameworks — werden auf dem Ziel neu aufgebaut. */
    public const array EXCLUDED_TABLES = [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    private const string META = '_meta.json';

    private const int CHUNK = 250;

    public static function defaultDirectory(): string
    {
        return database_path('seeders/data/snapshot');
    }

    /**
     * @return array<string, int> Tabelle → Anzahl Zeilen
     *
     * @throws JsonException
     */
    public function export(string $directory): array
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Der Ordner $directory lässt sich nicht anlegen.");
        }

        $tables = [];
        foreach ($this->tables() as $table) {
            $columns = Schema::getColumnListing($table);
            $query = DB::table($table);
            if (in_array('id', $columns, true)) {
                $query->orderBy('id');
            }

            $file = new SplFileObject($this->path($directory, $table), 'w');
            $count = 0;
            foreach ($query->cursor() as $row) {
                $file->fwrite(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
                $count++;
            }
            $tables[$table] = ['columns' => $columns, 'rows' => $count];
        }

        file_put_contents($directory.DIRECTORY_SEPARATOR.self::META, json_encode([
            'exported_at' => now()->toIso8601String(),
            'driver' => DB::connection()->getDriverName(),
            'tables' => $tables,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return array_map(fn (array $table) => $table['rows'], $tables);
    }

    public function hasExport(string $directory): bool
    {
        return is_file($directory.DIRECTORY_SEPARATOR.self::META);
    }

    /**
     * Gründe, warum der Export nicht in die aktuelle Datenbank passt: abweichendes Schema oder schon vorhandene Daten.
     *
     * @return list<string>
     *
     * @throws JsonException
     */
    public function importBlockers(string $directory): array
    {
        $exported = $this->meta($directory)['tables'];
        $current = $this->tables();
        $blockers = [];

        foreach (array_diff(array_keys($exported), $current) as $table) {
            $blockers[] = "Tabelle $table fehlt in der Zieldatenbank.";
        }
        foreach (array_diff($current, array_keys($exported)) as $table) {
            $blockers[] = "Tabelle $table fehlt im Export.";
        }
        foreach (array_intersect(array_keys($exported), $current) as $table) {
            if (Schema::getColumnListing($table) !== $exported[$table]['columns']) {
                $blockers[] = "Die Spalten von $table stimmen nicht mit dem Export überein.";
            }
            if (DB::table($table)->exists()) {
                $blockers[] = "Tabelle $table ist nicht leer.";
            }
        }

        return $blockers;
    }

    /**
     * @return array<string, int> Tabelle → Anzahl eingefügter Zeilen
     *
     * @throws JsonException
     * @throws Throwable
     */
    public function import(string $directory): array
    {
        if (! $this->hasExport($directory)) {
            throw new RuntimeException("Im Ordner $directory liegt kein Export.");
        }

        $blockers = $this->importBlockers($directory);
        if ($blockers !== []) {
            throw new RuntimeException(implode(' ', $blockers));
        }

        $plan = $this->insertionPlan(array_keys($this->meta($directory)['tables']));

        return DB::transaction(function () use ($directory, $plan) {
            $counts = [];
            foreach ($plan as $table => $deferred) {
                $counts[$table] = $this->insertTable($directory, $table, $deferred);
            }
            foreach ($plan as $table => $deferred) {
                if ($deferred !== []) {
                    $this->fillDeferred($directory, $table, $deferred);
                }
            }

            return $counts;
        });
    }

    /**
     * Reihenfolge der Tabellen nach Fremdschlüsseln; je Tabelle die Spalten, die erst nach dem Einfügen aller
     * Tabellen gesetzt werden (Verweise auf sich selbst oder auf eine Tabelle in einem Zyklus).
     *
     * @param  list<string>  $tables
     * @return array<string, list<string>>
     */
    public function insertionPlan(array $tables): array
    {
        $references = [];
        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                foreach ($foreignKey['columns'] as $column) {
                    $references[$table][$column] = $foreignKey['foreign_table'];
                }
            }
        }

        $plan = [];
        $remaining = $tables;
        while ($remaining !== []) {
            $ready = array_values(array_filter($remaining, fn (string $table) => array_diff(
                array_diff($references[$table] ?? [], [$table]), array_keys($plan)) === []));
            // Zyklus: die erste übrige Tabelle nehmen und ihre offenen Verweise später setzen.
            $next = $ready[0] ?? $remaining[0];

            $plan[$next] = array_keys(array_filter($references[$next] ?? [],
                fn (string $target) => $target === $next || ! isset($plan[$target])));
            $remaining = array_values(array_diff($remaining, [$next]));
        }

        return $plan;
    }

    /**
     * @param  list<string>  $deferred
     *
     * @throws JsonException
     */
    private function insertTable(string $directory, string $table, array $deferred): int
    {
        $count = 0;
        foreach ($this->chunks($directory, $table) as $rows) {
            if ($deferred !== []) {
                $rows = array_map(fn (array $row) => [...$row, ...array_fill_keys($deferred, null)], $rows);
            }
            DB::table($table)->insert($rows);
            $count += count($rows);
        }

        return $count;
    }

    /**
     * @param  list<string>  $deferred
     *
     * @throws JsonException
     */
    private function fillDeferred(string $directory, string $table, array $deferred): void
    {
        foreach ($this->chunks($directory, $table) as $rows) {
            foreach ($rows as $row) {
                $values = array_filter(array_intersect_key($row, array_flip($deferred)), fn ($value) => $value !== null);
                if ($values !== []) {
                    DB::table($table)->where('id', $row['id'])->update($values);
                }
            }
        }
    }

    /**
     * @return iterable<list<array<string, mixed>>>
     *
     * @throws JsonException
     */
    private function chunks(string $directory, string $table): iterable
    {
        $file = new SplFileObject($this->path($directory, $table));
        $chunk = [];
        while (! $file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }
            $chunk[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (count($chunk) === self::CHUNK) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /**
     * @return array{exported_at: string, driver: string, tables: array<string, array{columns: list<string>, rows: int}>}
     *
     * @throws JsonException
     */
    private function meta(string $directory): array
    {
        return json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.self::META), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function tables(): array
    {
        // Ohne Schema-Angabe liefert MySQL die Tabellen aller Datenbanken des Servers.
        $tables = Schema::getTableListing(Schema::getCurrentSchemaName(), false);
        $tables = array_values(array_diff($tables, self::EXCLUDED_TABLES));
        sort($tables);

        return $tables;
    }

    private function path(string $directory, string $table): string
    {
        return $directory.DIRECTORY_SEPARATOR.$table.'.ndjson';
    }
}

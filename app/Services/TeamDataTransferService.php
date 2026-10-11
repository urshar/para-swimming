<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Überträgt die Stammdaten aus dem Team-Manager-Umzug (Vereine, Athleten samt Unterlisten, Klassifizierer,
 * Klassifizierungshistorie) und die Rekorde von Dev nach Prod: export() schreibt je Tabelle eine JSON-Datei,
 * import() (über den TeamDataSeeder) liest sie in eine leere Datenbank ein.
 *
 * Die IDs bleiben erhalten, damit alle Verknüpfungen stimmen. Verweise auf Stammdaten, die die Seeder auf jeder
 * Umgebung anlegen (Nationen, Ausnahme-Codes, Lagen), werden über deren Code umgeschlüsselt — so ist es egal, ob die
 * IDs dort gleich vergeben wurden.
 *
 * Die Dateien enthalten Personen- und Gesundheitsdaten: Sie liegen in einem von Git ausgeschlossenen Ordner und
 * werden von Hand auf einem sicheren Weg auf den Zielserver kopiert.
 */
final readonly class TeamDataTransferService
{
    /** Einfügereihenfolge; je Tabelle die Spalten, die auf umzuschlüsselnde Stammdaten verweisen. */
    private const array TABLES = [
        'classifiers' => ['nation_id' => 'nations'],
        'clubs' => ['nation_id' => 'nations'],
        'athletes' => ['nation_id' => 'nations'],
        'athlete_sport_classes' => [],
        'athlete_level_history' => [],
        'athlete_club_history' => [],
        'athlete_exceptions' => ['exception_code_id' => 'exception_codes'],
        'athlete_classifications' => [],
        'athlete_classification_exceptions' => ['exception_code_id' => 'exception_codes'],
        'swim_records' => ['stroke_type_id' => 'stroke_types', 'nation_id' => 'nations', 'meet_nation_id' => 'nations'],
        'record_splits' => [],
        'relay_team_members' => [],
    ];

    /** Stammdaten-Tabellen → eindeutige Code-Spalte. */
    private const array LOOKUPS = ['nations' => 'code', 'exception_codes' => 'code', 'stroke_types' => 'code'];

    /** Verweise innerhalb der Rekorde: erst nach dem Einfügen aller Rekorde setzbar. */
    private const array RECORD_CHAIN = ['superseded_by_id', 'supersedes_id'];

    private const int CHUNK = 500;

    public static function defaultDirectory(): string
    {
        return database_path('seeders/data/team');
    }

    /**
     * @return array<string, int> Tabelle → Anzahl Zeilen
     *
     * @throws JsonException
     */
    public function export(string $directory): array
    {
        $blockers = $this->exportBlockers();
        if ($blockers !== []) {
            throw new RuntimeException(implode(' ', $blockers));
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Der Ordner $directory lässt sich nicht anlegen.");
        }

        $lookups = [];
        foreach (self::LOOKUPS as $table => $column) {
            $lookups[$table] = DB::table($table)->pluck($column, 'id')->all();
        }
        $this->write($directory, '_lookups', $lookups);

        $counts = [];
        foreach (array_keys(self::TABLES) as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();
            $this->write($directory, $table, $rows);
            $counts[$table] = count($rows);
        }

        return $counts;
    }

    /**
     * Gründe, warum noch nicht exportiert werden kann: Rekorde und Klassen-Hinweise aus Wettkämpfen hängen an
     * Ergebnissen bzw. Wettkämpfen, die auf dem Ziel nicht existieren.
     *
     * @return list<string>
     */
    public function exportBlockers(): array
    {
        $blockers = [];

        $records = DB::table('swim_records')->where(fn ($query) => $query
            ->whereNotNull('result_id')->orWhereNotNull('relay_result_id'))->count();
        if ($records > 0) {
            $blockers[] = "$records Rekord(e) stammen aus Wettkampfergebnissen — bitte die Wettkämpfe vorher löschen.";
        }

        $hints = DB::table('athlete_classifications')->whereNotNull('meet_id')->count();
        if ($hints > 0) {
            $blockers[] = "$hints Klassen-Hinweis(e) aus Ergebnislisten hängen an Wettkämpfen — bitte die Wettkämpfe vorher löschen.";
        }

        return $blockers;
    }

    /** true, wenn der Ordner einen vollständigen Export enthält. */
    public function hasExport(string $directory): bool
    {
        foreach (['_lookups', ...array_keys(self::TABLES)] as $file) {
            if (! is_file($this->path($directory, $file))) {
                return false;
            }
        }

        return true;
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
            throw new RuntimeException("Im Ordner $directory liegt kein vollständiger Export.");
        }

        if (DB::table('clubs')->exists() || DB::table('athletes')->exists()) {
            throw new RuntimeException('Es gibt bereits Vereine oder Athleten — der Import ist nur für eine leere Datenbank gedacht.');
        }

        $maps = $this->lookupMaps($this->read($directory, '_lookups'));
        $userIds = array_flip(DB::table('users')->pluck('id')->all());
        $tables = [];
        $chain = [];
        $missing = [];

        // Erst alles umschlüsseln, damit fehlende Stammdaten auffallen, bevor etwas geschrieben wird.
        foreach (self::TABLES as $table => $references) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->read($directory, $table);

            foreach ($rows as $i => $row) {
                foreach ($references as $column => $lookup) {
                    if ($row[$column] === null) {
                        continue;
                    }

                    $oldId = (int) $row[$column];

                    if (isset($maps[$lookup][$oldId])) {
                        $rows[$i][$column] = $maps[$lookup][$oldId];
                    } else {
                        $missing[$lookup.': '.($maps['_codes'][$lookup][$oldId] ?? 'ID '.$oldId)] = true;
                    }
                }

                // Benutzer gibt es auf dem Ziel womöglich nicht — die Änderung bleibt, nur ohne Benutzer.
                if ($table === 'athlete_level_history' && $row['user_id'] !== null && ! isset($userIds[(int) $row['user_id']])) {
                    $rows[$i]['user_id'] = null;
                }

                if ($table === 'swim_records') {
                    $links = array_intersect_key($row, array_flip(self::RECORD_CHAIN));
                    if (array_filter($links, fn ($value) => $value !== null) !== []) {
                        $chain[(int) $row['id']] = $links;
                    }
                    $rows[$i] = array_merge($rows[$i], array_fill_keys(self::RECORD_CHAIN, null));
                }
            }

            $tables[$table] = $rows;
        }

        if ($missing !== []) {
            throw new RuntimeException('Auf dem Ziel fehlen Stammdaten — bitte zuerst die Seeder ausführen ('
                .implode(', ', array_keys($missing)).').');
        }

        return DB::transaction(function () use ($tables, $chain) {
            $counts = [];

            foreach ($tables as $table => $rows) {
                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    // Klassifizierer gibt es teils schon aus dem ClassifiersSeeder — über die ID abgleichen.
                    $table === 'classifiers'
                        ? DB::table($table)->upsert($chunk, ['id'])
                        : DB::table($table)->insert($chunk);
                }

                $counts[$table] = count($rows);
            }

            foreach ($chain as $id => $links) {
                DB::table('swim_records')->where('id', $id)->update($links);
            }

            return $counts;
        });
    }

    /**
     * Alte ID → neue ID je Stammdaten-Tabelle, über den Code; unter "_codes" alte ID → Code für Fehlermeldungen.
     *
     * @param  array<string, array<int|string, string>>  $exported
     * @return array<string, array<int, int|string>|array<string, array<int, string>>>
     */
    private function lookupMaps(array $exported): array
    {
        $maps = ['_codes' => []];

        foreach (self::LOOKUPS as $table => $column) {
            $current = DB::table($table)->pluck('id', $column)->all();
            $maps[$table] = [];

            foreach ($exported[$table] ?? [] as $oldId => $code) {
                $maps['_codes'][$table][(int) $oldId] = $code;

                if (isset($current[$code])) {
                    $maps[$table][(int) $oldId] = (int) $current[$code];
                }
            }
        }

        return $maps;
    }

    /** @throws JsonException */
    private function write(string $directory, string $name, array $data): void
    {
        file_put_contents($this->path($directory, $name), json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws JsonException
     */
    private function read(string $directory, string $name): array
    {
        return json_decode((string) file_get_contents($this->path($directory, $name)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function path(string $directory, string $name): string
    {
        return rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name.'.json';
    }
}

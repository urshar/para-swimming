<?php

namespace App\Console\Commands;

use App\Services\NationalRecordMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Führt die Rekorde aus dem ÖBSV-Rekordimport (AUT.IND, AUT.IND.JG, AUT.REL, AUT.REL.JG) in die nationalen Ketten
 * AUT bzw. AUT.JR zusammen. Regeln: NationalRecordMergeService. Schreibt immer einen CSV-Bericht nach
 * storage/app/record-merge/; mit --dry-run wird nichts geändert.
 *
 *   php artisan records:merge-national-types --dry-run
 *   php artisan records:merge-national-types
 */
class MergeNationalRecordTypesCommand extends Command
{
    protected $signature = 'records:merge-national-types
        {--dry-run : nur Bericht erstellen, nichts ändern}';

    protected $description = 'Führt die ÖBSV-Rekordtypen AUT.IND/AUT.REL in die nationalen Rekordketten zusammen';

    public function handle(NationalRecordMergeService $merger): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirm('Rekorde wirklich zusammenführen? Entfernte Rekorde werden gelöscht.')) {
            return self::FAILURE;
        }

        try {
            $result = $merger->merge($dryRun);
        } catch (Throwable $e) {
            $this->error('Zusammenführung fehlgeschlagen, nichts geändert: '.$e->getMessage());

            return self::FAILURE;
        }

        $path = 'record-merge/'.now()->format('Ymd-His').($dryRun ? '-probelauf' : '-ausgefuehrt').'.csv';
        Storage::disk('local')->put($path, $this->csv($result['rows']));

        $this->info(($dryRun ? 'Probelauf' : 'Ausgeführt').' — Stand der ÖBSV-Liste: '.($result['cutoff'] ?? '—'));
        $this->table(['Kennzahl', 'Anzahl'], collect($result['summary'])->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->line('Bericht: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }

    /** CSV mit Semikolon und BOM, damit Excel Umlaute und Spalten richtig liest. */
    private function csv(array $rows): string
    {
        if ($rows === []) {
            return "\u{FEFF}";
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys($rows[0]), ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}".$csv;
    }
}

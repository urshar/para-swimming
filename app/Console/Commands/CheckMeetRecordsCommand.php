<?php

namespace App\Console\Commands;

use App\Models\Meet;
use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Führt den Rekord-Check (wie der Button am Wettkampf) für alle Wettkämpfe nach Datum aus, älteste zuerst — z. B. um die
 * Rekordketten nach einem Basis-Import neu aufzubauen. Prüft Endzeiten und Zwischenzeiten. Jeder Wettkampf läuft in einer
 * eigenen Transaktion; bricht einer ab, bleiben die schon geprüften erhalten. Mit --dry-run läuft alles in einer einzigen
 * Transaktion (spätere Wettkämpfe sehen die Rekorde der früheren) und wird am Ende zurückgerollt. Ein zweiter Lauf legt
 * nichts doppelt an.
 *
 *   php artisan records:check-meets --dry-run
 *   php artisan records:check-meets --meet=26
 */
class CheckMeetRecordsCommand extends Command
{
    protected $signature = 'records:check-meets
        {--dry-run : nur anzeigen, nichts ändern}
        {--meet= : nur diesen Wettkampf (ID) prüfen}';

    protected $description = 'Prüft alle Wettkämpfe nach Datum (älteste zuerst) auf Rekorde';

    /** @throws Throwable */
    public function handle(RecordCheckerService $checker): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $meetId = $this->option('meet');

        $meets = Meet::query()
            ->when($meetId !== null, fn ($q) => $q->whereKey((int) $meetId))
            ->oldest('start_date')
            ->orderBy('id')
            ->get();

        if ($meets->isEmpty()) {
            $this->error($meetId !== null ? 'Wettkampf '.$meetId.' nicht gefunden.' : 'Keine Wettkämpfe vorhanden.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->confirm($meets->count().' Wettkampf/Wettkämpfe jetzt auf Rekorde prüfen?')) {
            return self::FAILURE;
        }

        $rows = [];
        $totals = ['checked' => 0, 'new' => 0, 'pending' => 0, 'invalid' => 0];

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            foreach ($meets as $meet) {
                $result = $dryRun
                    ? $checker->checkMeet($meet)
                    : DB::transaction(fn () => $checker->checkMeet($meet));

                $counts = [
                    'checked' => $result['checked'],
                    'new' => count($result['new_records']),
                    'pending' => count($result['pending_records']),
                    'invalid' => count(array_unique(array_map(fn (SwimRecord $r) => $r->id, $result['invalid_records']))),
                ];

                foreach ($counts as $key => $value) {
                    $totals[$key] += $value;
                }

                $rows[] = [
                    $meet->id,
                    $meet->start_date?->format('d.m.Y'),
                    $meet->name,
                    $counts['checked'],
                    $counts['new'],
                    $counts['pending'],
                    $counts['invalid'],
                ];
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        $this->table(['Wk', 'Datum', 'Wettkampf', 'Geprüft', 'Neu', 'Ausstehend', 'Ungültig'], $rows);

        $summary = $meets->count().' Wettkampf/Wettkämpfe, '.$totals['checked'].' Ergebnis(se) geprüft: '
            .$totals['new'].' neue(r) Rekord(e), '.$totals['pending'].' ausstehend, '
            .$totals['invalid'].' spätere(r) Rekord(e) ungültig';

        $this->info($dryRun ? 'Probelauf — '.$summary.'. Nichts geändert.' : $summary.'.');

        if ($totals['pending'] > 0) {
            $this->warn('Ausstehende Rekorde zählen erst nach der Bestätigung zur Kette — bitte in der Rekordliste prüfen.');
        }

        return self::SUCCESS;
    }
}

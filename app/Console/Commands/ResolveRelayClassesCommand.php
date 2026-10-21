<?php

namespace App\Console\Commands;

use App\Models\RelayResult;
use App\Services\RelayClassValidator;
use Illuminate\Console\Command;

/**
 * Berechnet die Staffelklasse aller Staffelergebnisse neu, die keine oder eine ungültige Klasse haben (z. B. "S1" aus
 * einer Splash-Fehleingabe handicap="1"). Die Klasse kommt aus den Klassen der Mitglieder (RelayClassValidator);
 * lässt sie sich nicht bestimmen, wird eine ungültige Klasse geleert. Gültige Klassen bleiben unverändert. Mit
 * --dry-run werden die Änderungen nur angezeigt.
 *
 *   php artisan relays:resolve-classes --dry-run
 *   php artisan relays:resolve-classes
 */
class ResolveRelayClassesCommand extends Command
{
    protected $signature = 'relays:resolve-classes
        {--dry-run : nur anzeigen, nichts ändern}';

    protected $description = 'Berechnet fehlende oder ungültige Staffelklassen aus den Mitgliedern neu';

    public function handle(RelayClassValidator $validator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $changes = RelayResult::query()
            ->where(fn ($q) => $q->whereNull('relay_class')->orWhereNotIn('relay_class', RelayClassValidator::RELAY_CLASSES))
            ->with(['meet:id,name,start_date', 'club:id,name', 'swimEvent.strokeType', 'members.athlete.sportClasses'])
            ->get()
            ->map(fn (RelayResult $relay): array => [
                'relay' => $relay,
                'class' => $relay->swimEvent ? $validator->resolveFromMembers($relay->members, $relay->swimEvent) : null,
            ])
            ->filter(fn (array $change): bool => $change['class'] !== $change['relay']->relay_class)
            ->values();

        if ($changes->isEmpty()) {
            $this->info('Keine Staffelklassen zu ändern.');

            return self::SUCCESS;
        }

        $this->table(['Wettkampf', 'Datum', 'Bewerb', 'Verein', 'bisher', 'neu'], $changes->map(fn (array $change): array => [
            $change['relay']->meet?->name,
            $change['relay']->meet?->start_date?->format('d.m.Y'),
            $change['relay']->swimEvent?->display_name,
            $change['relay']->club?->name,
            $change['relay']->relay_class ?? '–',
            $change['class'] ?? '–',
        ])->all());

        if ($dryRun) {
            $this->info('Probelauf — '.$changes->count().' Staffelklasse(n) würden geändert.');

            return self::SUCCESS;
        }

        if (! $this->confirm($changes->count().' Staffelklasse(n) ändern?')) {
            return self::FAILURE;
        }

        foreach ($changes as $change) {
            $change['relay']->update(['relay_class' => $change['class']]);
        }

        $this->info($changes->count().' Staffelklasse(n) geändert.');

        return self::SUCCESS;
    }
}

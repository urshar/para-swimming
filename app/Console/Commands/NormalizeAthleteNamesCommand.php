<?php

namespace App\Console\Commands;

use App\Models\Athlete;
use App\Models\RelayTeamMember;
use App\Support\PersonName;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Bringt Vor- und Familiennamen, die ganz in Großbuchstaben gespeichert sind, in Groß- und Kleinschreibung
 * (App\Support\PersonName) — Athleten und Staffelmitglieder der Rekorde. Mit --dry-run wird nur angezeigt.
 *
 *   php artisan athletes:normalize-names --dry-run
 *   php artisan athletes:normalize-names
 */
class NormalizeAthleteNamesCommand extends Command
{
    protected $signature = 'athletes:normalize-names
        {--dry-run : nur anzeigen, nichts ändern}';

    protected $description = 'Bringt ganz groß geschriebene Namen von Athleten und Staffelmitgliedern in Groß- und Kleinschreibung';

    public function handle(): int
    {
        $changes = [
            ...$this->collect(Athlete::query(), 'Athlet'),
            ...$this->collect(RelayTeamMember::query(), 'Staffelmitglied'),
        ];
        $rows = array_map(fn (array $change) => [$change['label'], $change['person']->getKey(), $change['before'], $change['after']], $changes);

        if ($changes === []) {
            $this->info('Keine ganz groß geschriebenen Namen gefunden.');

            return self::SUCCESS;
        }

        $this->table(['Art', 'ID', 'Bisher', 'Neu'], $rows);

        if ($this->option('dry-run')) {
            $this->info('Probelauf — nichts geändert.');

            return self::SUCCESS;
        }

        if (! $this->confirm(count($changes).' Name(n) ändern?')) {
            return self::FAILURE;
        }

        foreach ($changes as $change) {
            $change['person']->update($change['updates']);
        }

        $this->info(count($changes).' Name(n) geändert.');

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Athlete>|Builder<RelayTeamMember>  $query
     * @return list<array{label: string, person: Model, updates: array<string, string>, before: string, after: string}>
     */
    private function collect(Builder $query, string $label): array
    {
        $changes = [];

        foreach ($query->select(['id', 'first_name', 'last_name'])->orderBy('id')->get() as $person) {
            $first = (string) $person->getAttribute('first_name');
            $last = (string) $person->getAttribute('last_name');
            $updates = array_filter([
                'first_name' => PersonName::normalize($first) !== $first ? PersonName::normalize($first) : null,
                'last_name' => PersonName::normalize($last) !== $last ? PersonName::normalize($last) : null,
            ]);

            if ($updates !== []) {
                $changes[] = [
                    'label' => $label,
                    'person' => $person,
                    'updates' => $updates,
                    'before' => $last.', '.$first,
                    'after' => ($updates['last_name'] ?? $last).', '.($updates['first_name'] ?? $first),
                ];
            }
        }

        return $changes;
    }
}

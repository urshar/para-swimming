<?php

namespace App\Console\Commands;

use App\Enums\RoleTemplate;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Weist allen Nicht-Admins ohne gemerkte Vorlage eine Rechte-Vorlage zu (Spec club-permissions Phase 2) — für den
 * Bestand vor der Umstellung auf Rechte je Modul. Benutzer mit Vorlage bleiben unberührt. Mit --dry-run wird nur
 * angezeigt.
 *
 *   php artisan permissions:assign-template club --only-with-club --dry-run
 *   php artisan permissions:assign-template club --only-with-club
 */
class AssignRoleTemplateCommand extends Command
{
    protected $signature = 'permissions:assign-template
        {template : Vorlage (club, coach, empty)}
        {--only-with-club : nur Benutzer mit zugeordnetem Verein}
        {--dry-run : nur anzeigen, nichts ändern}';

    protected $description = 'Weist Nicht-Admins ohne Vorlage eine Rechte-Vorlage zu';

    /**
     * @throws Throwable
     */
    public function handle(PermissionTemplateService $service): int
    {
        $template = RoleTemplate::tryFrom((string) $this->argument('template'));

        if ($template === null) {
            $this->error('Unbekannte Vorlage. Erlaubt: '.implode(', ', array_column(RoleTemplate::cases(), 'value')));

            return self::FAILURE;
        }

        $users = User::with('club')
            ->where('is_admin', false)
            ->whereNull('role_template')
            ->when($this->option('only-with-club'), fn ($q) => $q->whereNotNull('club_id'))
            ->orderBy('name')
            ->get();

        if ($users->isEmpty()) {
            $this->info('Keine Benutzer ohne Vorlage gefunden.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'E-Mail', 'Verein'],
            $users->map(fn (User $user) => [$user->id, $user->name, $user->email, $user->club?->name ?? '–'])->all(),
        );

        if ($this->option('dry-run')) {
            $this->info('Probelauf — nichts geändert.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Vorlage \"{$template->label()}\" auf {$users->count()} Benutzer anwenden?")) {
            return self::FAILURE;
        }

        foreach ($users as $user) {
            $service->applyTemplate($user, $template);
        }

        $this->info("Vorlage \"{$template->label()}\" auf {$users->count()} Benutzer angewendet.");

        return self::SUCCESS;
    }
}

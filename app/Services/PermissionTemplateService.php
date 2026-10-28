<?php

namespace App\Services;

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pflege der Rechte-Matrix eines Benutzers (Spec club-permissions §4.1): Vorlage anwenden, Vorlage für alle
 * Benutzer neu anwenden, Matrix einzeln setzen. Die Matrix wird immer komplett ersetzt; Module ohne Zugriff werden
 * nicht gespeichert.
 *
 * Matrix-Format wie RoleTemplate::permissions(): Schlüssel = Module-Wert, Wert = ['read' => …, 'write' => …].
 */
final readonly class PermissionTemplateService
{
    /**
     * Ersetzt die Rechte des Benutzers durch die der Vorlage und merkt die Vorlage am Benutzer.
     *
     * @throws Throwable
     */
    public function applyTemplate(User $user, RoleTemplate $template): void
    {
        DB::transaction(function () use ($user, $template) {
            $this->replacePermissions($user, $template->permissions());
            $user->update(['role_template' => $template]);
        });
    }

    /**
     * Wendet die (geänderte) Vorlage erneut auf alle Nicht-Admins an, die sie gemerkt haben. Einzeln abweichende
     * Rechte dieser Benutzer gehen dabei verloren.
     *
     * @return int Anzahl der betroffenen Benutzer
     *
     * @throws Throwable
     */
    public function reapplyTemplate(RoleTemplate $template): int
    {
        $users = User::where('role_template', $template->value)
            ->where('is_admin', false)
            ->get();

        foreach ($users as $user) {
            $this->applyTemplate($user, $template);
        }

        return $users->count();
    }

    /**
     * Setzt die Matrix einzeln (Benutzerverwaltung). Die gemerkte Vorlage bleibt als Ausgangspunkt stehen.
     *
     * @param  array<string, array{read: AccessScope, write: AccessScope}>  $matrix
     *
     * @throws ValidationException bei unbekanntem Modul, unzulässiger Kombination oder "eigener Verein" ohne Verein
     * @throws Throwable
     */
    public function setPermissions(User $user, array $matrix): void
    {
        $this->validate($user, $matrix);

        DB::transaction(fn () => $this->replacePermissions($user, $matrix));
    }

    /**
     * @param  array<string, array{read: AccessScope, write: AccessScope}>  $matrix
     *
     * @throws ValidationException
     */
    private function validate(User $user, array $matrix): void
    {
        $errors = [];

        foreach ($matrix as $key => $scopes) {
            $module = Module::tryFrom($key);

            if ($module === null) {
                $errors["permissions.$key"] = "Unbekanntes Modul \"$key\".";

                continue;
            }

            if (! $module->allows($scopes['read'], $scopes['write'])) {
                $errors["permissions.$key"] = "{$module->label()}: Diese Kombination aus Lesen und Schreiben ist nicht "
                    .'zulässig (Schreiben darf nicht weiter reichen als Lesen).';

                continue;
            }

            $usesOwn = $scopes['read'] === AccessScope::Own || $scopes['write'] === AccessScope::Own;

            if ($usesOwn && $user->club_id === null) {
                $errors["permissions.$key"] = "{$module->label()}: \"Eigener Verein\" braucht einen zugeordneten Verein.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param  array<string, array{read: AccessScope, write: AccessScope}>  $matrix */
    private function replacePermissions(User $user, array $matrix): void
    {
        UserModulePermission::where('user_id', $user->id)->delete();

        foreach ($matrix as $module => $scopes) {
            if ($scopes['read'] === AccessScope::None && $scopes['write'] === AccessScope::None) {
                continue;
            }

            UserModulePermission::create([
                'user_id' => $user->id,
                'module' => $module,
                'read_scope' => $scopes['read'],
                'write_scope' => $scopes['write'],
            ]);
        }

        $user->unsetRelation('modulePermissions');
    }
}

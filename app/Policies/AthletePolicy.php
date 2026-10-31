<?php

namespace App\Policies;

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Models\Athlete;
use App\Models\User;

/**
 * Sichtbarkeit und Pflege einzelner Athleten (Spec club-permissions §4.4, §4.8, P3c-2).
 *
 * Gegenstück zu Athlete::scopeVisibleTo() für Listen: Lesen "alle" → jeder Athlet, "eigener Verein" → nur die des
 * eigenen Vereins. Vergleicht nur die club_id, löst also keine zusätzliche Abfrage aus — wichtig, weil Views die
 * Prüfung je Zeile aufrufen, um Links auf fremde Athleten auszublenden. Welche Felder ein Schreiber mit "eigener
 * Verein" setzen darf, regelt App\Support\AthleteFieldRules.
 */
class AthletePolicy
{
    public function view(User $user, Athlete $athlete): bool
    {
        return $this->allows($user->readScope(Module::Athletes), $user, $athlete);
    }

    public function create(User $user): bool
    {
        return $user->canWrite(Module::Athletes);
    }

    public function update(User $user, Athlete $athlete): bool
    {
        return $this->allows($user->writeScope(Module::Athletes), $user, $athlete);
    }

    private function allows(AccessScope $scope, User $user, Athlete $athlete): bool
    {
        return match ($scope) {
            AccessScope::All => true,
            AccessScope::Own => $athlete->club_id !== null && $athlete->club_id === $user->club_id,
            AccessScope::None => false,
        };
    }
}

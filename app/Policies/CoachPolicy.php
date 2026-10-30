<?php

namespace App\Policies;

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Models\Coach;
use App\Models\User;

/**
 * Sichtbarkeit und Pflege der Trainer (Spec club-permissions §4.6, Phase 8): "alle" → jeder Trainer, "eigener
 * Verein" → nur die des eigenen Vereins. Gegenstück zu Coach::scopeVisibleTo() für Listen.
 */
class CoachPolicy
{
    public function view(User $user, Coach $coach): bool
    {
        return $this->allows($user->readScope(Module::Coaches), $user, $coach->club_id);
    }

    public function create(User $user): bool
    {
        return $user->canWrite(Module::Coaches);
    }

    /** Trainer in genau diesem Verein anlegen (Vorbelegung von der Vereinsseite, Prüfung beim Speichern). */
    public function createForClub(User $user, int $clubId): bool
    {
        return $this->allows($user->writeScope(Module::Coaches), $user, $clubId);
    }

    public function update(User $user, Coach $coach): bool
    {
        return $this->allows($user->writeScope(Module::Coaches), $user, $coach->club_id);
    }

    /** Löschen nur ohne verknüpften Benutzer (P8-3), sonst deaktivieren. */
    public function delete(User $user, Coach $coach): bool
    {
        return $this->update($user, $coach) && ! $coach->user()->exists();
    }

    private function allows(AccessScope $scope, User $user, int $clubId): bool
    {
        return match ($scope) {
            AccessScope::All => true,
            AccessScope::Own => $user->club_id !== null && $clubId === $user->club_id,
            AccessScope::None => false,
        };
    }
}

<?php

namespace App\Policies;

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Models\Club;
use App\Models\User;

/**
 * Pflege und Kontaktdaten eines Vereins (Spec club-permissions §4.6, Phase 7): Schreiben "alle" → jeder Verein,
 * "eigener Verein" → nur der eigene. Welche Felder dabei frei sind, regelt App\Support\ClubFieldRules.
 */
class ClubPolicy
{
    public function update(User $user, Club $club): bool
    {
        return match ($user->writeScope(Module::Clubs)) {
            AccessScope::All => true,
            AccessScope::Own => $user->club_id === $club->id,
            AccessScope::None => false,
        };
    }

    /** Kontaktname, E-Mail und Adresse sehen nur Admins und der eigene Verein (P7-4). */
    public function viewContact(User $user, Club $club): bool
    {
        return $user->is_admin || ($user->club_id !== null && $user->club_id === $club->id);
    }
}

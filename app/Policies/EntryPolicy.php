<?php

// ═══════════════════════════════════════════════════════════════════════════
// 1. app/Policies/EntryPolicy.php
// ═══════════════════════════════════════════════════════════════════════════

namespace App\Policies;

use App\Models\Meet;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * EntryPolicy
 *
 * Steuert, ob ein User Meldungen für einen Wettkampf verwalten darf.
 *
 * Regeln:
 *  - Admin       → immer erlaubt
 *  - Vereins-User → nur, wenn kein Meldeschluss oder Meldeschluss noch nicht abgelaufen
 *  - Kein Club   → nie erlaubt
 */
class EntryPolicy
{
    /**
     * Alias für manageEntries — wird für create/update/delete einzeln verwendet,
     * falls man später feiner differenzieren möchte.
     */
    public function createEntry(User $user, Meet $meet): bool
    {
        return $this->manageEntries($user, $meet);
    }

    /**
     * Darf der User überhaupt Meldungen für diesen Wettkampf anlegen/bearbeiten/löschen?
     *
     * Wird verwendet als:
     *   $this->authorize('manageEntries', $meet);
     * oder:
     *   Gate::allows('manageEntries', $meet)
     */
    public function manageEntries(User $user, Meet $meet): bool
    {
        // Admins dürfen immer
        if ($user->is_admin) {
            return true;
        }

        // Kein Verein → nie
        if (! $user->club_id) {
            return false;
        }

        // Kein Meldeschluss gesetzt → erlaubt
        if (! $meet->entries_deadline) {
            return true;
        }

        // Meldeschluss noch nicht erreicht → erlaubt
        // today() verwendet die App-Timezone aus config/app.php
        return Carbon::today()->lte(Carbon::parse($meet->entries_deadline));
    }

    public function updateEntry(User $user, Meet $meet): bool
    {
        return $this->manageEntries($user, $meet);
    }

    public function deleteEntry(User $user, Meet $meet): bool
    {
        return $this->manageEntries($user, $meet);
    }
}

<?php

// ═══════════════════════════════════════════════════════════════════════════
// 1. app/Policies/EntryPolicy.php
// ═══════════════════════════════════════════════════════════════════════════

namespace App\Policies;

use App\Enums\Module;
use App\Models\Meet;
use App\Models\User;

/**
 * EntryPolicy
 *
 * Steuert, ob ein User Meldungen für einen Wettkampf verwalten darf.
 *
 * Regeln (Spec club-permissions §4.1):
 *  - Meldungen schreiben "alle"            → immer erlaubt (auch nach Meldeschluss)
 *  - Meldungen schreiben "eigener Verein" → nur, wenn kein Meldeschluss, Meldeschluss noch nicht
 *                                          abgelaufen oder wiedereröffnet (Meet::acceptsClubEntries)
 *  - sonst                                 → nie erlaubt
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
        // Melden für alle Vereine: immer
        if ($user->canWriteAll(Module::Entries)) {
            return true;
        }

        // Ohne Melderecht oder ohne Verein → nie ("eigener Verein" ohne Verein zählt als kein Recht)
        if (! $user->canWrite(Module::Entries) || ! $user->club_id) {
            return false;
        }

        // Kein Meldeschluss, Meldeschluss noch nicht erreicht oder vom Admin wiedereröffnet → erlaubt
        return $meet->acceptsClubEntries();
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

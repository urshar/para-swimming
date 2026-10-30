<?php

namespace App\Policies;

use App\Enums\Module;
use App\Models\Athlete;
use App\Models\AthletePerformanceNote;
use App\Models\User;

/**
 * Berechtigungen für Leistungsnotizen (Spec "WPS Rankings" §7.5, Spec club-permissions P3c-3).
 *
 * Notizen sind **nicht** verbandsweit sichtbar, anders als die Ranglisten selbst: Krankheit
 * und Verletzung sind Gesundheitsangaben. Sichtbar für die Verbandsverwaltung (Admin) und den
 * eigenen Verein — das ist die vorsichtigere Voreinstellung, und sie lässt sich später
 * lockern; das Gegenteil ginge nicht. Bewusst öffnet auch Athleten lesen "alle" die Notizen
 * fremder Vereine nicht: Sie sollen nicht mit jeder verbandsweiten Leseberechtigung mitwandern.
 * Für den eigenen Verein braucht es Athleten lesen (ansehen) bzw. Athleten schreiben (anlegen,
 * ändern, löschen).
 */
class AthletePerformanceNotePolicy
{
    /** Darf der Nutzer die Notizen dieses Athleten sehen? */
    public function viewForAthlete(User $user, Athlete $athlete): bool
    {
        return $user->is_admin
            || ($this->ownClub($user, $athlete->club_id) && $user->canRead(Module::Athletes));
    }

    /** Darf der Nutzer eine Notiz zu diesem Athleten anlegen? */
    public function createForAthlete(User $user, Athlete $athlete): bool
    {
        return $user->is_admin
            || ($this->ownClub($user, $athlete->club_id) && $user->canWrite(Module::Athletes));
    }

    /**
     * Darf der Nutzer diese Notiz ändern oder löschen?
     *
     * Admins alle, Vereinsnutzer nur die ihres eigenen Vereins. Bewusst nicht auf den
     * Verfasser eingeschränkt: Innerhalb eines Vereins wechseln die Zuständigkeiten, und eine
     * veraltete Notiz muss auch dann korrigierbar sein, wenn ihr Verfasser nicht mehr da ist.
     */
    public function update(User $user, AthletePerformanceNote $note): bool
    {
        return $user->is_admin
            || ($this->ownClub($user, $note->athlete?->getAttribute('club_id')) && $user->canWrite(Module::Athletes));
    }

    public function delete(User $user, AthletePerformanceNote $note): bool
    {
        return $this->update($user, $note);
    }

    private function ownClub(User $user, mixed $clubId): bool
    {
        return $user->club_id !== null && $user->club_id === $clubId;
    }
}

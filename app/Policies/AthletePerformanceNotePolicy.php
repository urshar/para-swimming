<?php

namespace App\Policies;

use App\Models\Athlete;
use App\Models\AthletePerformanceNote;
use App\Models\User;

/**
 * Berechtigungen für Leistungsnotizen (Spec "WPS Rankings" §7.5).
 *
 * Notizen sind **nicht** verbandsweit sichtbar, anders als die Ranglisten selbst: Krankheit
 * und Verletzung sind Gesundheitsangaben. Sichtbar für die Verbandsverwaltung und den
 * eigenen Verein — das ist die vorsichtigere Voreinstellung, und sie lässt sich später
 * lockern; das Gegenteil ginge nicht.
 */
class AthletePerformanceNotePolicy
{
    /** Darf der Nutzer die Notizen dieses Athleten sehen? */
    public function viewForAthlete(User $user, Athlete $athlete): bool
    {
        return $user->is_admin
            || ($user->club_id !== null && $user->club_id === $athlete->club_id);
    }

    /** Darf der Nutzer eine Notiz zu diesem Athleten anlegen? */
    public function createForAthlete(User $user, Athlete $athlete): bool
    {
        return $this->viewForAthlete($user, $athlete);
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
            || ($user->club_id !== null && $user->club_id === $note->athlete?->getAttribute('club_id'));
    }

    public function delete(User $user, AthletePerformanceNote $note): bool
    {
        return $this->update($user, $note);
    }
}

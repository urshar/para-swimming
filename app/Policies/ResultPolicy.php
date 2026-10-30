<?php

namespace App\Policies;

use App\Models\Result;
use App\Models\User;

/**
 * Einzelergebnis lesen (Spec club-permissions F5, Phase 9): Lesen "alle" → jedes Ergebnis, "eigener Verein" → nur
 * Ergebnisse von Athleten des eigenen Vereins (Result::isVisibleTo).
 */
class ResultPolicy
{
    public function view(User $user, Result $result): bool
    {
        return $result->isVisibleTo($user);
    }
}

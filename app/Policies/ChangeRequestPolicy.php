<?php

namespace App\Policies;

use App\Models\Athlete;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\AthleteFieldRules;
use Illuminate\Support\Facades\Gate;

/**
 * Anträge stellen und zurückziehen (Spec club-permissions §4.5). Anträge braucht nur, wer Name und Verein nicht
 * selbst ändern darf (AthleteFieldRules::restricted(), also "Athleten schreiben — eigener Verein"); entschieden
 * wird ausschließlich im Admin-Bereich (Middleware admin).
 */
class ChangeRequestPolicy
{
    /** "Meine Anträge" und Ummeldung beantragen: Schreiber "eigener Verein" mit Verein. */
    public function create(User $user): bool
    {
        return $user->club_id !== null && AthleteFieldRules::for($user)->restricted()
            && Gate::forUser($user)->allows('create', Athlete::class);
    }

    /** Namensänderung für einen Athleten des eigenen Vereins. */
    public function requestName(User $user, Athlete $athlete): bool
    {
        return $this->create($user) && Gate::forUser($user)->allows('update', $athlete);
    }

    public function withdraw(User $user, ChangeRequest $request): bool
    {
        return $request->isOpen() && $request->club_id !== null && $request->club_id === $user->club_id
            && $this->create($user);
    }
}

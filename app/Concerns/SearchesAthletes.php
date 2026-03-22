<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait SearchesAthletes
{
    /**
     * Filtert eine Query nach Athlet-Name (last_name oder first_name).
     * Wird in EntryController und ResultController verwendet.
     */
    protected function applyAthleteSearch(Builder $query, string $search): Builder
    {
        return $query->whereHas('athlete', function (Builder $q) use ($search) {
            $q->where('last_name', 'like', '%'.$search.'%')
                ->orWhere('first_name', 'like', '%'.$search.'%');
        });
    }
}

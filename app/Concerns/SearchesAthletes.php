<?php

namespace App\Concerns;

use App\Support\NameSearch;
use Illuminate\Database\Eloquent\Builder;

trait SearchesAthletes
{
    /**
     * Filtert eine Query nach Athlet-Name (jeder Suchteil in last_name oder first_name, siehe NameSearch).
     * Wird in EntryController und ResultController verwendet.
     */
    protected function applyAthleteSearch(Builder $query, string $search): Builder
    {
        return $query->whereHas(
            'athlete',
            fn (Builder $q) => NameSearch::apply($q, $search, ['last_name', 'first_name']),
        );
    }
}

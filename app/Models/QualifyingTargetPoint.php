<?php

namespace App\Models;

use App\Support\SportClassSorter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualifyingTargetPoint extends Model
{
    protected $fillable = [
        'qualifying_time_list_id',
        'sport_class',
        'points',
    ];

    protected $casts = [
        'points' => 'integer',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function qualifyingTimeList(): BelongsTo
    {
        return $this->belongsTo(QualifyingTimeList::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /** Natürlicher Sortierschlüssel (S10 nach S9, nicht nach S1). */
    public function getSortKeyAttribute(): string
    {
        return SportClassSorter::key($this->sport_class);
    }
}

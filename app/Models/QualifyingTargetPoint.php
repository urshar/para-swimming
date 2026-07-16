<?php

namespace App\Models;

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
}

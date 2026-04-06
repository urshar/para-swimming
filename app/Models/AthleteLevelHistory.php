<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AthleteLevelHistory extends Model
{
    protected $table = 'athlete_level_history';

    protected $fillable = [
        'athlete_id',
        'user_id',
        'level',
        'previous_level',
        'changed_at',
        'notes',
    ];

    protected $casts = [
        'changed_at' => 'date',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

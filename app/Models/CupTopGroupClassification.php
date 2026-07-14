<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CupTopGroupClassification extends Model
{
    protected $fillable = [
        'cup_id',
        'athlete_id',
        'is_top_group',
        'reason',
        'reference_points',
        'calculated_at',
    ];

    protected $casts = [
        'is_top_group' => 'boolean',
        'reference_points' => 'integer',
        'calculated_at' => 'datetime',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function cup(): BelongsTo
    {
        return $this->belongsTo(Cup::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }
}

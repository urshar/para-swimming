<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CupAgeGroupSetting extends Model
{
    protected $fillable = [
        'cup_id',
        'age_group_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function cup(): BelongsTo
    {
        return $this->belongsTo(Cup::class);
    }

    public function ageGroup(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class);
    }
}

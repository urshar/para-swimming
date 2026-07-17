<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualifyingExcludedDiscipline extends Model
{
    protected $fillable = [
        'base_time_discipline_id',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function discipline(): BelongsTo
    {
        return $this->belongsTo(BaseTimeDiscipline::class, 'base_time_discipline_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordSplit extends Model
{
    protected $fillable = [
        'swim_record_id',
        'distance',
        'split_time',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function swimRecord(): BelongsTo
    {
        return $this->belongsTo(SwimRecord::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getFormattedSplitTimeAttribute(): string
    {
        return Entry::formatTime($this->split_time);
    }
}

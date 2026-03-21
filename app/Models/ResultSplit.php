<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultSplit extends Model
{
    protected $fillable = [
        'result_id',
        'distance',
        'split_time',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getFormattedSplitTimeAttribute(): string
    {
        return Entry::formatTime($this->split_time);
    }
}

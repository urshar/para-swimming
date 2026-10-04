<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RelayResultSplit extends Model
{
    protected $fillable = [
        'relay_result_id',
        'distance',
        'split_time',
    ];

    public function relayResult(): BelongsTo
    {
        return $this->belongsTo(RelayResult::class);
    }

    public function getFormattedSplitTimeAttribute(): string
    {
        return Entry::formatTime($this->split_time);
    }
}

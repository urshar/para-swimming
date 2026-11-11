<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesMeetRecordCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RelayResultSplit extends Model
{
    use InvalidatesMeetRecordCheck;

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

    // ── Prüfstatus Rekordcheck ────────────────────────────────────────────────

    protected function recordRelevantAttributes(): array
    {
        return ['relay_result_id', 'distance', 'split_time'];
    }

    protected function recordCheckMeetIds(): array
    {
        return RelayResult::query()
            ->whereKey(array_filter([$this->relay_result_id, $this->getOriginal('relay_result_id')]))
            ->distinct()->pluck('meet_id')->map(fn ($id) => (int) $id)->values()->all();
    }
}

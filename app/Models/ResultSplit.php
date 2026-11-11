<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesMeetRecordCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultSplit extends Model
{
    use InvalidatesMeetRecordCheck;

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

    // ── Prüfstatus Rekordcheck ────────────────────────────────────────────────

    protected function recordRelevantAttributes(): array
    {
        return ['result_id', 'distance', 'split_time'];
    }

    protected function recordCheckMeetIds(): array
    {
        return Result::query()
            ->whereKey(array_filter([$this->result_id, $this->getOriginal('result_id')]))
            ->distinct()->pluck('meet_id')->map(fn ($id) => (int) $id)->values()->all();
    }
}

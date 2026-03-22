<?php

namespace App\Models;

use App\Support\TimeParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entry extends Model
{
    protected $fillable = [
        'meet_id',
        'swim_event_id',
        'athlete_id',
        'club_id',
        'entry_time',
        'entry_time_code',
        'entry_course',
        'status',
        'sport_class',
        'heat',
        'lane',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function meet(): BelongsTo
    {
        return $this->belongsTo(Meet::class);
    }

    public function swimEvent(): BelongsTo
    {
        return $this->belongsTo(SwimEvent::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getFormattedEntryTimeAttribute(): string
    {
        if (! $this->entry_time) {
            return $this->entry_time_code ?? 'NT';
        }

        return self::formatTime($this->entry_time);
    }

    public static function formatTime(int $centiseconds): string
    {
        return TimeParser::display($centiseconds);
    }

    public static function parseTime(string $time): ?int
    {
        return TimeParser::parse($time);
    }
}

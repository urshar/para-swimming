<?php

namespace App\Models;

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
        $hours = intdiv($centiseconds, 360000);
        $minutes = intdiv($centiseconds % 360000, 6000);
        $seconds = intdiv($centiseconds % 6000, 100);
        $cs = $centiseconds % 100;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $seconds, $cs);
        }
        if ($minutes > 0) {
            return sprintf('%d:%02d.%02d', $minutes, $seconds, $cs);
        }

        return sprintf('%d.%02d', $seconds, $cs);
    }

    /** LENEX swim time Format "HH:MM:SS.ss" → Hundertstelsekunden */
    public static function parseTime(string $time): ?int
    {
        if (empty($time) || $time === 'NT') {
            return null;
        }
        // Format: HH:MM:SS.ss
        if (preg_match('/^(\d+):(\d{2}):(\d{2})\.(\d{2})$/', $time, $m)) {
            return ((int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]) * 100 + (int) $m[4];
        }
        // Format: M:SS.ss (Kurzform ohne Stunden)
        if (preg_match('/^(\d+):(\d{2})\.(\d{2})$/', $time, $m)) {
            return ((int) $m[1] * 60 + (int) $m[2]) * 100 + (int) $m[3];
        }

        return null;
    }
}

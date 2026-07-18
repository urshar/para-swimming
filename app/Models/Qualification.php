<?php

namespace App\Models;

use App\Support\TimeParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Qualification extends Model
{
    protected $fillable = [
        'meet_id',
        'qualifying_time_list_id',
        'qualifying_time_id',
        'athlete_id',
        'result_id',
        'club_id',
        'sport_class',
        'swim_time_centiseconds',
        'points',
        'qualified_at',
    ];

    protected $casts = [
        'swim_time_centiseconds' => 'integer',
        'points' => 'integer',
        'qualified_at' => 'date',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function meet(): BelongsTo
    {
        return $this->belongsTo(Meet::class);
    }

    public function qualifyingTimeList(): BelongsTo
    {
        return $this->belongsTo(QualifyingTimeList::class);
    }

    public function qualifyingTime(): BelongsTo
    {
        return $this->belongsTo(QualifyingTime::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    /** Snapshot des Vereins zum Qualifikationszeitpunkt — kann vom aktuellen Verein des Athleten abweichen. */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getFormattedSwimTimeAttribute(): string
    {
        return TimeParser::display($this->swim_time_centiseconds);
    }
}

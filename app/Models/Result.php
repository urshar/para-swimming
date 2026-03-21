<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Result extends Model
{
    protected $fillable = [
        'meet_id',
        'swim_event_id',
        'athlete_id',
        'club_id',
        'swim_time',
        'status',
        'sport_class',
        'points',
        'heat',
        'lane',
        'place',
        'reaction_time',
        'comment',
        'is_world_record',
        'is_european_record',
        'is_national_record',
        'lenex_result_id',
    ];

    protected $casts = [
        'is_world_record' => 'boolean',
        'is_european_record' => 'boolean',
        'is_national_record' => 'boolean',
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

    public function splits(): HasMany
    {
        return $this->hasMany(ResultSplit::class)->orderBy('distance');
    }

    public function swimRecord(): HasMany
    {
        return $this->hasMany(SwimRecord::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getFormattedSwimTimeAttribute(): string
    {
        if (! $this->swim_time) {
            return $this->status ?? '—';
        }

        return Entry::formatTime($this->swim_time);
    }

    public function isValid(): bool
    {
        return $this->swim_time !== null
            && ! in_array($this->status, ['DSQ', 'DNS', 'DNF', 'WDR']);
    }

    public function hasRecords(): bool
    {
        return $this->is_world_record
            || $this->is_european_record
            || $this->is_national_record;
    }
}

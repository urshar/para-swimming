<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Meet extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'city',
        'nation_id',
        'course',
        'start_date',
        'end_date',
        'organizer',
        'altitude',
        'timing',
        'entry_type',
        'lenex_status',
        'is_open',
        'swrid',
        'lenex_meet_id',
        'entries_deadline',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_open' => 'boolean',
        'entries_deadline' => 'date',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function clubs(): BelongsToMany
    {
        return $this->belongsToMany(Club::class, 'meet_club');
    }

    public function swimEvents(): HasMany
    {
        return $this->hasMany(SwimEvent::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getDateRangeAttribute(): string
    {
        if (! $this->end_date || $this->start_date->eq($this->end_date)) {
            return $this->start_date->format('d.m.Y');
        }

        return $this->start_date->format('d.m.Y').' – '.$this->end_date->format('d.m.Y');
    }

    public function isDeadlinePassed(): bool
    {
        if (! $this->entries_deadline) {
            return false;
        }

        return Carbon::today()->gt($this->entries_deadline);
    }

    public function hasDeadline(): bool
    {
        return $this->entries_deadline !== null;
    }
}

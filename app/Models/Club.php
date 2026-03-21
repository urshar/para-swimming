<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Club extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'short_name',
        'code',
        'nation_id',
        'type',
        'swrid',
        'lenex_club_id',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function athletes(): HasMany
    {
        return $this->hasMany(Athlete::class);
    }

    public function meets(): BelongsToMany
    {
        return $this->belongsToMany(Meet::class, 'meet_club');
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

    public function getDisplayNameAttribute(): string
    {
        return $this->short_name ?? $this->name;
    }
}

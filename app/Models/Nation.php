<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Nation extends Model
{
    protected $fillable = [
        'code',
        'name_de',
        'name_en',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function clubs(): HasMany
    {
        return $this->hasMany(Club::class);
    }

    public function athletes(): HasMany
    {
        return $this->hasMany(Athlete::class);
    }

    public function meets(): HasMany
    {
        return $this->hasMany(Meet::class);
    }

    public function swimRecords(): HasMany
    {
        return $this->hasMany(SwimRecord::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        return $this->code.' – '.$this->name_de;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

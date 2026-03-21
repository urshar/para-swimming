<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ExceptionCode extends Model
{
    protected $fillable = [
        'code',
        'name_en',
        'name_de',
        'description_en',
        'description_de',
        'wps_rules',
        'applies_to',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function athletes(): BelongsToMany
    {
        return $this->belongsToMany(Athlete::class, 'athlete_exceptions')
            ->withPivot('category', 'note')
            ->withTimestamps();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Codes die für einen bestimmten Schwimmstil gelten */
    public function scopeForStroke($query, string $stroke)
    {
        return $query->where(function ($q) use ($stroke) {
            $q->whereNull('applies_to')           // allgemeine Codes immer
                ->orWhere('applies_to', $stroke)
                ->orWhere('applies_to', 'FLY_BREAST'); // gilt für FLY und BREAST
        });
    }

    /** Nur allgemeine Codes (stilunabhängig) */
    public function scopeGeneral($query)
    {
        return $query->whereNull('applies_to');
    }
}

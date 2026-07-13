<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgeGroup extends Model
{
    protected $fillable = [
        'code',
        'name_de',
        'min_age',
        'max_age',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'min_age' => 'integer',
        'max_age' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function matchesAge(int $age): bool
    {
        if ($this->min_age !== null && $age < $this->min_age) {
            return false;
        }

        if ($this->max_age !== null && $age > $this->max_age) {
            return false;
        }

        return true;
    }
}

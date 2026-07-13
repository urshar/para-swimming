<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportClassGroup extends Model
{
    protected $fillable = [
        'code',
        'name_de',
        'is_virtual',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_virtual' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function members(): HasMany
    {
        return $this->hasMany(SportClassGroupMember::class);
    }

    public function cupSettings(): HasMany
    {
        return $this->hasMany(CupGroupSetting::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

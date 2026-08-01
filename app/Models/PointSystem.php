<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PointSystem extends Model
{
    public const string CODE_WORLD_AQUATICS = 'WA';

    public const string CODE_WPS = 'WPS';

    public const string CODE_OBSV_1000 = 'OBSV1000';

    protected $fillable = [
        'name',
        'code',
        'description',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function meets(): BelongsToMany
    {
        return $this->belongsToMany(Meet::class, 'meet_point_system')
            ->withPivot('wps_point_version_id')
            ->withTimestamps();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function isWps(): bool
    {
        return $this->code === self::CODE_WPS;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BaseTimeSportClass extends Model
{
    protected $fillable = [
        'code',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function baseTimes(): HasMany
    {
        return $this->hasMany(BaseTime::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}

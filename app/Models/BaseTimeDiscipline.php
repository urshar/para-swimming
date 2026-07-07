<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BaseTimeDiscipline extends Model
{
    protected $fillable = [
        'stroke_type_id',
        'distance',
        'relay_count',
        'code',
    ];

    protected $casts = [
        'distance' => 'integer',
        'relay_count' => 'integer',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function strokeType(): BelongsTo
    {
        return $this->belongsTo(StrokeType::class);
    }

    public function baseTimes(): HasMany
    {
        return $this->hasMany(BaseTime::class);
    }

    public function shorterInRules(): HasMany
    {
        return $this->hasMany(BaseTimeDerivationRule::class, 'shorter_discipline_id');
    }

    public function longerInRules(): HasMany
    {
        return $this->hasMany(BaseTimeDerivationRule::class, 'longer_discipline_id');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getIsRelayAttribute(): bool
    {
        return $this->relay_count > 1;
    }

    public function getDisplayNameAttribute(): string
    {
        $prefix = $this->is_relay ? "{$this->relay_count}x" : '';

        return "$prefix{$this->distance}m {$this->strokeType?->name_de}";
    }
}

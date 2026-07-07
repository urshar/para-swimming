<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BaseTimeVersion extends Model
{
    protected $fillable = [
        'label',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function baseTimes(): HasMany
    {
        return $this->hasMany(BaseTime::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Filtert auf die Version, deren Gültigkeitszeitraum ein bestimmtes Datum umfasst.
     * Wird später für die World-Aquatics-Punkteberechnung anhand des Wettkampfdatums benötigt.
     */
    public function scopeValidOn(Builder $query, string $date): Builder
    {
        return $query->where('valid_from', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date);
            });
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Prüft, ob sich der angegebene Zeitraum mit einer bestehenden Version überschneidet.
     * $excludeId beim Bearbeiten einer bestehenden Version angeben, um sie selbst auszuschließen.
     */
    public static function overlapsExisting(string $validFrom, ?string $validUntil, ?int $excludeId = null): bool
    {
        return self::query()
            ->when($excludeId, fn (Builder $q) => $q->where('id', '!=', $excludeId))
            ->where('valid_from', '<=', $validUntil ?? '9999-12-31')
            ->where(function (Builder $q) use ($validFrom) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $validFrom);
            })
            ->exists();
    }

    public function getDisplayNameAttribute(): string
    {
        $from = $this->valid_from?->format('Y-m-d');
        $until = $this->valid_until?->format('Y-m-d') ?? '∞';

        return "$this->label ($from – $until)";
    }
}

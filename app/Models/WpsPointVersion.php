<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WpsPointVersion extends Model
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_ARCHIVED = 'archived';

    /** @used-by WpsPointsPhase1Test zur Validierung erlaubter status-Werte */
    public const array STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'label',
        'year',
        'version',
        'source',
        'official',
        'status',
        'valid_from',
        'valid_until',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $casts = [
        'year' => 'integer',
        'official' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function parameters(): HasMany
    {
        return $this->hasMany(WpsPointParameter::class);
    }

    public function scmDerivations(): HasMany
    {
        return $this->hasMany(WpsScmDerivation::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Filtert auf Versionen, deren Gültigkeitszeitraum ein bestimmtes Datum umfasst.
     *
     * Aufgebaut wie BaseTimeVersion::scopeValidOn(), damit die Zuordnung nach Wettkampfdatum
     * bei WPS und World Aquatics dasselbe Verhalten zeigt. Eine Version ohne valid_from gilt
     * als nicht datumsgebunden und wird hier nicht berücksichtigt — sie ist ausschließlich
     * über die explizite Zuordnung am Meet erreichbar.
     *
     * Die obere Grenze von valid_from trägt bewusst eine Uhrzeit: date-Spalten werden je nach
     * Treiber als "2026-01-01" oder als "2026-01-01 00:00:00" abgelegt. Im zweiten Fall wäre
     * "2026-01-01 00:00:00" ≤ "2026-01-01" als Zeichenkettenvergleich falsch, und eine
     * Veranstaltung genau am ersten Gültigkeitstag fiele aus der Zuordnung. Bei valid_until
     * stellt sich das Problem nicht, dort zeigt der Vergleich in die andere Richtung.
     */
    public function scopeValidOn(Builder $query, string $date): Builder
    {
        return $query->whereNotNull('valid_from')
            ->where('valid_from', '<=', "$date 23:59:59")
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date);
            });
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Eine Version darf nur gelöscht werden, solange kein Ergebnis auf sie verweist —
     * sonst ginge die Nachvollziehbarkeit historischer Punkte verloren. In dem Fall ist
     * sie zu archivieren.
     */
    public function isDeletable(): bool
    {
        return ! $this->results()->exists();
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->version !== null
            ? "$this->label (v$this->version)"
            : $this->label;
    }
}

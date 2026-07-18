<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualifyingTimeList extends Model
{
    protected $fillable = [
        'year',
        'is_active',
        'qualification_period_start',
        'qualification_period_end',
    ];

    protected $casts = [
        'year' => 'integer',
        'is_active' => 'boolean',
        'qualification_period_start' => 'date',
        'qualification_period_end' => 'date',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function targetPoints(): HasMany
    {
        return $this->hasMany(QualifyingTargetPoint::class);
    }

    public function times(): HasMany
    {
        return $this->hasMany(QualifyingTime::class);
    }

    /** Meets, die dieser Richtzeitenliste zugeordnet sind (i.d.R. genau ein ÖSTM & ÖM-Meet). */
    public function meets(): HasMany
    {
        return $this->hasMany(Meet::class);
    }

    /** Ermittelte Qualifikationen für diese Richtzeitenliste (Phase 4/5/6). */
    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /** Zielpunkte für eine Sportklasse — Default 100, falls keine Zeile hinterlegt ist. */
    public function targetPointsFor(string $sportClass): int
    {
        return $this->targetPoints->firstWhere('sport_class', strtoupper($sportClass))?->points ?? 100;
    }

    /**
     * Nur die Liste mit dem höchsten Wettkampfjahr darf bearbeitet/gelöscht
     * werden (Phase 3 — Historisierung: frühere Jahre bleiben unverändert
     * und dauerhaft abrufbar, aber schreibgeschützt).
     */
    public function isLatest(): bool
    {
        return $this->year === (static::max('year') ?? $this->year);
    }
}

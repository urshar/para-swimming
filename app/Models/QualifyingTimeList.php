<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualifyingTimeList extends Model
{
    protected $fillable = [
        'year',
        'is_active',
    ];

    protected $casts = [
        'year' => 'integer',
        'is_active' => 'boolean',
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

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /** Zielpunkte für eine Sportklasse — Default 100, falls keine Zeile hinterlegt ist. */
    public function targetPointsFor(string $sportClass): int
    {
        return $this->targetPoints->firstWhere('sport_class', strtoupper($sportClass))?->points ?? 100;
    }
}

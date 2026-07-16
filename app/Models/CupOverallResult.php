<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $cup_id
 * @property int $athlete_id
 * @property int $club_id
 * @property int $sport_class_group_id
 * @property string $gender
 * @property int|null $age_group_id
 * @property int $total_points
 * @property int $rounds_counted
 * @property array<int, int> $counted_meet_ids
 * @property Carbon $calculated_at
 * @property int|null $rank Rang innerhalb einer Wertungskategorie — wird zur
 *                          Laufzeit von OverallRankingService::rankedBracket() gesetzt, ist KEIN
 *                          Datenbank-Feld und wird nicht persistiert.
 * @property Collection|null $rounds Runden-Aufschlüsselung
 *                                   (Punkte/Sportklasse je Meet) — wird zur Laufzeit von
 *                                   CupOverallRankingController::attachRoundBreakdown() gesetzt, ist KEIN
 *                                   Datenbank-Feld und wird nicht persistiert.
 */
class CupOverallResult extends Model
{
    protected $fillable = [
        'cup_id',
        'athlete_id',
        'club_id',
        'sport_class_group_id',
        'gender',
        'age_group_id',
        'total_points',
        'rounds_counted',
        'counted_meet_ids',
        'calculated_at',
    ];

    protected $casts = [
        'total_points' => 'integer',
        'rounds_counted' => 'integer',
        'counted_meet_ids' => 'array',
        'calculated_at' => 'datetime',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function cup(): BelongsTo
    {
        return $this->belongsTo(Cup::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function sportClassGroup(): BelongsTo
    {
        return $this->belongsTo(SportClassGroup::class);
    }

    public function ageGroup(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Eine Wertungskategorie (z.B. "Damen PI Jugend") innerhalb eines Cups.
     * $gender = null bedeutet: Damen und Herren gemeinsam gewertet (Erik).
     */
    public function scopeForBracket(
        Builder $query,
        int $cupId,
        ?string $gender,
        int $sportClassGroupId,
        ?int $ageGroupId
    ): Builder {
        return $query
            ->where('cup_id', $cupId)
            ->when($gender !== null, fn (Builder $q) => $q->where('gender', $gender))
            ->where('sport_class_group_id', $sportClassGroupId)
            ->when(
                $ageGroupId === null,
                fn (Builder $q) => $q->whereNull('age_group_id'),
                fn (Builder $q) => $q->where('age_group_id', $ageGroupId)
            )
            ->orderByDesc('total_points');
    }
}

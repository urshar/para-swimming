<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $rank Rang innerhalb einer Wertungskategorie — wird
 *                          zur Laufzeit von DailyRankingService::rankedBracket() gesetzt, ist KEIN
 *                          Datenbank-Feld und wird nicht persistiert.
 */
class CupDailyResult extends Model
{
    protected $fillable = [
        'cup_id',
        'meet_id',
        'athlete_id',
        'club_id',
        'result_id',
        'sport_class_group_id',
        'gender',
        'points',
        'calculated_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'calculated_at' => 'datetime',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function cup(): BelongsTo
    {
        return $this->belongsTo(Cup::class);
    }

    public function meet(): BelongsTo
    {
        return $this->belongsTo(Meet::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    public function sportClassGroup(): BelongsTo
    {
        return $this->belongsTo(SportClassGroup::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Eine Wertungskategorie (z.B. "Damen PI") innerhalb eines Meets.
     * $gender = null bedeutet: Damen und Herren gemeinsam gewertet (Erik).
     */
    public function scopeForBracket(Builder $query, int $meetId, ?string $gender, int $sportClassGroupId): Builder
    {
        return $query
            ->where('meet_id', $meetId)
            ->when($gender !== null, fn (Builder $q) => $q->where('gender', $gender))
            ->where('sport_class_group_id', $sportClassGroupId)
            ->orderByDesc('points');
    }
}

<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AthleteKaderMembership extends Model
{
    protected $fillable = [
        'athlete_id',
        'kader_type_id',
        'valid_from',
        'valid_until',
        'notes',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function kaderType(): BelongsTo
    {
        return $this->belongsTo(KaderType::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Nur Zugehörigkeiten, die an einem bestimmten Stichtag gültig sind.
     * valid_from/valid_until = null bedeutet "unbegrenzt gültig" in diese Richtung.
     */
    public function scopeActiveOn(Builder $query, Carbon|string $date): Builder
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return $query
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
            })
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date);
            });
    }
}

<?php

namespace App\Models;

use App\Enums\TopGroupReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Top-Gruppen-Einstufung eines Athleten in einem Cup-Jahr (Spec cup-top-group.md). is_top_group ist die wirksame
 * Einstufung: die Hand-Einstufung, falls gesetzt, sonst calculated_is_top_group (Kader bzw. Zwei-Jahres-Regel).
 *
 * @property bool $is_top_group
 * @property bool $calculated_is_top_group
 * @property ?TopGroupReason $reason
 * @property ?bool $previous_is_top_group
 * @property ?int $last_start_year
 * @property ?int $last_start_points
 * @property ?int $prior_start_year
 * @property ?int $prior_start_points
 * @property ?bool $manual_is_top_group
 * @property ?string $manual_note
 */
class CupTopGroupClassification extends Model
{
    protected $fillable = [
        'cup_id',
        'athlete_id',
        'is_top_group',
        'calculated_is_top_group',
        'reason',
        'previous_is_top_group',
        'last_start_year',
        'last_start_points',
        'prior_start_year',
        'prior_start_points',
        'manual_is_top_group',
        'manual_note',
        'manual_set_by',
        'manual_set_at',
        'calculated_at',
    ];

    protected $casts = [
        'is_top_group' => 'boolean',
        'calculated_is_top_group' => 'boolean',
        'reason' => TopGroupReason::class,
        'previous_is_top_group' => 'boolean',
        'last_start_year' => 'integer',
        'last_start_points' => 'integer',
        'prior_start_year' => 'integer',
        'prior_start_points' => 'integer',
        'manual_is_top_group' => 'boolean',
        'manual_set_at' => 'datetime',
        'calculated_at' => 'datetime',
    ];

    public function isManual(): bool
    {
        return $this->manual_is_top_group !== null;
    }

    // ── Relationen ────────────────────────────────────────────────────────────

    public function cup(): BelongsTo
    {
        return $this->belongsTo(Cup::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function manualSetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_set_by');
    }
}

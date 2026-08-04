<?php

namespace App\Models;

use App\Support\TimeParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Result extends Model
{
    /** Offiziell von World Para Swimming veröffentlichte Parameter (LCM). */
    public const string WPS_TYPE_OFFICIAL = 'official';

    /** Aus LCM-Parametern abgeleitet (SCM) — nicht von World Para Swimming anerkannt. */
    public const string WPS_TYPE_ESTIMATED = 'estimated';

    /** @used-by WpsPointsPhase1Test zur Validierung erlaubter wps_calculation_type-Werte */
    public const array WPS_CALCULATION_TYPES = [
        self::WPS_TYPE_OFFICIAL,
        self::WPS_TYPE_ESTIMATED,
    ];

    protected $fillable = [
        'meet_id',
        'swim_event_id',
        'athlete_id',
        'club_id',
        'swim_time',
        'status',
        'sport_class',
        'points',
        'wps_points',
        'wps_point_version_id',
        'wps_point_parameter_id',
        'wps_calculation_type',
        'wps_calculated_at',
        'wps_estimated_lcm_time',
        'wps_conversion_factor_id',
        'heat',
        'lane',
        'place',
        'reaction_time',
        'comment',
        'is_world_record',
        'is_european_record',
        'is_national_record',
        'is_junior_record',
        'is_regional_record',
        'is_regional_junior_record',
        'lenex_result_id',
    ];

    protected $casts = [
        'is_world_record' => 'boolean',
        'is_european_record' => 'boolean',
        'is_national_record' => 'boolean',
        'is_junior_record' => 'boolean',
        'is_regional_record' => 'boolean',
        'is_regional_junior_record' => 'boolean',
        'wps_points' => 'integer',
        'wps_calculated_at' => 'datetime',
        'wps_estimated_lcm_time' => 'integer',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function meet(): BelongsTo
    {
        return $this->belongsTo(Meet::class);
    }

    public function swimEvent(): BelongsTo
    {
        return $this->belongsTo(SwimEvent::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function wpsPointVersion(): BelongsTo
    {
        return $this->belongsTo(WpsPointVersion::class, 'wps_point_version_id');
    }

    public function wpsPointParameter(): BelongsTo
    {
        return $this->belongsTo(WpsPointParameter::class, 'wps_point_parameter_id');
    }

    public function wpsConversionFactor(): BelongsTo
    {
        return $this->belongsTo(WpsScmConversionFactor::class, 'wps_conversion_factor_id');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(ResultSplit::class)->orderBy('distance');
    }

    public function swimRecord(): HasMany
    {
        return $this->hasMany(SwimRecord::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getFormattedSwimTimeAttribute(): string
    {
        if (! $this->swim_time) {
            return $this->status ?? '—';
        }

        return TimeParser::display($this->swim_time);
    }

    public function isValid(): bool
    {
        return $this->swim_time !== null
            && ! in_array($this->status, ['DSQ', 'DNS', 'DNF', 'WDR']);
    }

    public function hasWpsPoints(): bool
    {
        return $this->wps_points !== null;
    }

    /**
     * Ob die WPS-Punkte auf abgeleiteten SCM-Parametern beruhen und daher als nicht offiziell
     * gekennzeichnet werden müssen.
     */
    public function hasEstimatedWpsPoints(): bool
    {
        return $this->wps_calculation_type === self::WPS_TYPE_ESTIMATED;
    }

    /** Ob die WPS-Punkte auf einer umgerechneten Kurzbahnzeit beruhen. */
    public function hasConvertedWpsTime(): bool
    {
        return $this->wps_estimated_lcm_time !== null;
    }

    /**
     * Die geschätzte Langbahnzeit im Anzeigeformat.
     *
     * Fachlich oft wichtiger als die Punktzahl: Sie lässt sich unmittelbar gegen
     * internationale Melde- und Finalzeiten halten (Spec §2.3).
     */
    public function getFormattedEstimatedLcmTimeAttribute(): ?string
    {
        return $this->wps_estimated_lcm_time !== null
            ? TimeParser::display($this->wps_estimated_lcm_time)
            : null;
    }

    public function hasRecords(): bool
    {
        return $this->is_world_record
            || $this->is_european_record
            || $this->is_national_record
            || $this->is_junior_record
            || $this->is_regional_record
            || $this->is_regional_junior_record;
    }
}

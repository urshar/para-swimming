<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Gompertz-Parametersatz der WPS-Punkteberechnung.
 *
 *   q = a * e^(-e^(b - c/p))     p = Zeit in Sekunden
 *
 * Die Berechnung selbst liegt bewusst nicht hier, sondern im WpsPointCalculator (Phase 2) —
 * Models enthalten in diesem Projekt keine Geschäftslogik.
 */
class WpsPointParameter extends Model
{
    public const string COURSE_LCM = 'LCM';

    public const string COURSE_SCM = 'SCM';

    /** @used-by WpsPointsPhase1Test zur Validierung erlaubter course-Werte */
    public const array COURSES = [
        self::COURSE_LCM,
        self::COURSE_SCM,
    ];

    public const string GENDER_MALE = 'M';

    public const string GENDER_FEMALE = 'F';

    /** @used-by WpsPointsPhase1Test zur Validierung erlaubter gender-Werte */
    public const array GENDERS = [
        self::GENDER_MALE,
        self::GENDER_FEMALE,
    ];

    protected $fillable = [
        'wps_point_version_id',
        'course',
        'gender',
        'stroke_type_id',
        'distance',
        'relay_count',
        'sport_class',
        'parameter_a',
        'parameter_b',
        'parameter_c',
        'official',
        'source',
        'notes',
    ];

    protected $casts = [
        'distance' => 'integer',
        'relay_count' => 'integer',
        'parameter_a' => 'float',
        'parameter_b' => 'float',
        'parameter_c' => 'float',
        'official' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function version(): BelongsTo
    {
        return $this->belongsTo(WpsPointVersion::class, 'wps_point_version_id');
    }

    public function strokeType(): BelongsTo
    {
        return $this->belongsTo(StrokeType::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForCourse(Builder $query, string $course): Builder
    {
        return $query->where('course', $course);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Der Berechnungstyp, der am Ergebnis gespeichert wird.
     *
     * Er leitet sich aus dem Parametersatz ab, nicht aus der Bahnlänge — damit die
     * Kennzeichnung korrekt bleibt, falls World Para Swimming später offizielle
     * SCM-Parameter veröffentlicht.
     */
    public function calculationType(): string
    {
        return $this->official ? Result::WPS_TYPE_OFFICIAL : Result::WPS_TYPE_ESTIMATED;
    }

    /** Sportklassen-Kategorie ohne Nummer: "SB8" → "SB". */
    public function getSportClassCategoryAttribute(): ?string
    {
        return preg_match('/^(S|SB|SM)\d+$/', strtoupper(trim((string) $this->sport_class)), $m) === 1
            ? $m[1]
            : null;
    }
}

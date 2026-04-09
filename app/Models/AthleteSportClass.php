<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AthleteSportClass extends Model
{
    const string SCOPE_INTL = 'INTL';

    const string SCOPE_NAT = 'NAT';

    const array SCOPES = [
        self::SCOPE_INTL => 'International (SDMS)',
        self::SCOPE_NAT => 'National only (ÖBSV)',
    ];

    const string STATUS_NEW = 'NEW';

    const string STATUS_CONFIRMED = 'CONFIRMED';

    const string STATUS_REVIEW = 'REVIEW';

    const string STATUS_FRD = 'FRD';

    const string STATUS_NE = 'NE';

    const array STATUSES = [
        self::STATUS_NEW => 'New',
        self::STATUS_CONFIRMED => 'Confirmed',
        self::STATUS_REVIEW => 'Review',
        self::STATUS_FRD => 'Fixed Review Date',
        self::STATUS_NE => 'Not Eligible',
    ];

    const array STATUS_COLORS = [
        self::STATUS_NEW => 'blue',
        self::STATUS_CONFIRMED => 'emerald',
        self::STATUS_REVIEW => 'amber',
        self::STATUS_FRD => 'orange',
        self::STATUS_NE => 'red',
    ];

    protected $fillable = [
        'athlete_id',
        'category',
        'class_number',
        'sport_class',
        'classification_scope',
        'classification_status',
        'frd_year',
    ];

    protected $casts = [
        'frd_year' => 'integer',
    ];

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    /**
     * Lesbares Status-Label inkl. FRD-Jahr.
     * z.B. "FRD 2027" oder "Confirmed"
     */
    public function getStatusLabelAttribute(): string
    {
        if (! $this->classification_status) {
            return '–';
        }

        $label = self::STATUSES[$this->classification_status] ?? $this->classification_status;

        if ($this->classification_status === self::STATUS_FRD && $this->frd_year) {
            $label .= ' '.$this->frd_year;
        }

        return $label;
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->classification_status] ?? 'zinc';
    }

    // ── Relationen ────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::saving(function (AthleteSportClass $model) {
            if ($model->category && $model->class_number) {
                $model->sport_class = $model->category.$model->class_number;
            }
            // frd_year nur bei FRD behalten
            if ($model->classification_status !== self::STATUS_FRD) {
                $model->frd_year = null;
            }
        });
    }
}

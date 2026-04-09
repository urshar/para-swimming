<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AthleteClassification extends Model
{
    const string SCOPE_INTL = 'INTL';

    const string SCOPE_NAT = 'NAT';

    const string STATUS_NEW = 'NEW';

    const string STATUS_CONFIRMED = 'CONFIRMED';

    const string STATUS_REVIEW = 'REVIEW';

    const string STATUS_FRD = 'FRD';

    const string STATUS_NE = 'NE';

    const array SCOPES = [
        self::SCOPE_INTL => 'International (SDMS)',
        self::SCOPE_NAT => 'Nur national (ÖBSV)',
    ];

    const array STATUSES = [
        self::STATUS_NEW => 'New',
        self::STATUS_CONFIRMED => 'Confirmed',
        self::STATUS_REVIEW => 'Review',
        self::STATUS_FRD => 'Fixed Review Date',
        self::STATUS_NE => 'Not Eligible',
    ];

    // Badge-Farben pro Status
    const array STATUS_COLORS = [
        self::STATUS_NEW => 'blue',
        self::STATUS_CONFIRMED => 'emerald',
        self::STATUS_REVIEW => 'amber',
        self::STATUS_FRD => 'orange',
        self::STATUS_NE => 'red',
    ];

    protected $fillable = [
        'athlete_id',
        'med_classifier_id',
        'tech1_classifier_id',
        'tech2_classifier_id',
        'classified_at',
        'location',
        'result_s',
        'result_sb',
        'result_sm',
        'classification_scope',
        'classification_status',
        'frd_year',
        'notes',
    ];

    protected $casts = [
        'classified_at' => 'date',
        'frd_year' => 'integer',
    ];

    // ── Relationen (Exceptions) ──────────────────────────────────────────────

    public function exceptions(): BelongsToMany
    {
        return $this->belongsToMany(
            ExceptionCode::class,
            'athlete_classification_exceptions',
            'athlete_classification_id',
            'exception_code_id'
        )
            ->withPivot('category', 'note')
            ->withTimestamps();
    }

    // ── Relationen ────────────────────────────────────────────────────────────

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function medClassifier(): BelongsTo
    {
        return $this->belongsTo(Classifier::class, 'med_classifier_id');
    }

    public function tech1Classifier(): BelongsTo
    {
        return $this->belongsTo(Classifier::class, 'tech1_classifier_id');
    }

    public function tech2Classifier(): BelongsTo
    {
        return $this->belongsTo(Classifier::class, 'tech2_classifier_id');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Lesbare Statusanzeige inkl. FRD-Jahr.
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

    /**
     * Alle drei Sportklassen-Ergebnisse als lesbare Kurzdarstellung.
     * z.B. "S4 / SB3 / SM4" — null-Werte werden übersprungen.
     */
    public function getSportClassResultsDisplayAttribute(): string
    {
        return collect([
            $this->result_s,
            $this->result_sb,
            $this->result_sm,
        ])->filter()->join(' / ') ?: '–';
    }

    /**
     * Badge-Farbe für den aktuellen Status.
     */
    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->classification_status] ?? 'zinc';
    }

    /**
     * Scope-Label: "INTL" oder "NAT"
     */
    public function getScopeLabelAttribute(): string
    {
        return self::SCOPES[$this->classification_scope] ?? $this->classification_scope;
    }
}

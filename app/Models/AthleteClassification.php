<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class AthleteClassification extends Model
{
    protected $fillable = [
        'athlete_id',
        'med_classifier_id',
        'tech1_classifier_id',
        'tech2_classifier_id',
        'classified_at',
        'location',
        'sport_class_result',
        'status',
        'notes',
    ];

    protected $casts = [
        'classified_at' => 'date',
    ];

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
     * Alle drei Klassifizierer als Collection.
     */
    public function getClassifiersAttribute(): Collection
    {
        return collect([
            'med' => $this->medClassifier,
            'tech1' => $this->tech1Classifier,
            'tech2' => $this->tech2Classifier,
        ])->filter();
    }
}

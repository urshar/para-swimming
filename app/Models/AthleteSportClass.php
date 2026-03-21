<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AthleteSportClass extends Model
{
    protected $fillable = [
        'athlete_id',
        'category',
        'class_number',
        'sport_class',
        'status',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        // sport_class automatisch aus category + class_number generieren
        static::saving(function (AthleteSportClass $model) {
            if ($model->category && $model->class_number) {
                $model->sport_class = $model->category.$model->class_number;
            }
        });
    }
}

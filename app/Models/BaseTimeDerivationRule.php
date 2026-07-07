<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BaseTimeDerivationRule extends Model
{
    protected $fillable = [
        'base_time_category_id',
        'shorter_discipline_id',
        'longer_discipline_id',
        'ratio_reference_category_id',
        'ratio_shorter_discipline_id',
        'ratio_longer_discipline_id',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(BaseTimeCategory::class, 'base_time_category_id');
    }

    public function shorterDiscipline(): BelongsTo
    {
        return $this->belongsTo(BaseTimeDiscipline::class, 'shorter_discipline_id');
    }

    public function longerDiscipline(): BelongsTo
    {
        return $this->belongsTo(BaseTimeDiscipline::class, 'longer_discipline_id');
    }

    /** Kategorie, deren Basiswerte für den Durchschnitts-Wachstumsfaktor herangezogen werden. */
    public function ratioReferenceCategory(): BelongsTo
    {
        return $this->belongsTo(BaseTimeCategory::class, 'ratio_reference_category_id');
    }

    /** Bewerbs-Paar, dessen Wachstumsfaktor anstelle des eigenen Paars verwendet wird (z.B. 1500FR nutzt "400FR to 800FR"). */
    public function ratioShorterDiscipline(): BelongsTo
    {
        return $this->belongsTo(BaseTimeDiscipline::class, 'ratio_shorter_discipline_id');
    }

    public function ratioLongerDiscipline(): BelongsTo
    {
        return $this->belongsTo(BaseTimeDiscipline::class, 'ratio_longer_discipline_id');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /** Kategorie, aus der die Basiswerte für den Durchschnitts-Wachstumsfaktor gelesen werden. */
    public function resolveRatioCategory(): BaseTimeCategory
    {
        return $this->ratioReferenceCategory ?? $this->category;
    }

    /** Bewerbs-Paar (kürzer, länger), aus dem der Durchschnitts-Wachstumsfaktor berechnet wird. */
    public function resolveRatioDisciplinePair(): array
    {
        return [
            $this->ratioShorterDiscipline ?? $this->shorterDiscipline,
            $this->ratioLongerDiscipline ?? $this->longerDiscipline,
        ];
    }
}

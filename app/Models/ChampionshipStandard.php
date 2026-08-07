<?php

namespace App\Models;

use App\Support\TimeParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Einzelne Qualifikationsnorm je Bewerb, Geschlecht und Sportklasse
 * (Spec "WPS Qualification" §5.2).
 *
 * Zwei Normebenen ([Q2]):
 *   - MQS / MET      — die Vorgaben von World Para Swimming
 *   - ÖBSV-Norm      — kann schärfer sein, weil die Startplätze begrenzt sind
 *
 * Prozentsatz und errechnete Zeit werden beide gespeichert; die Zeit ist von Hand
 * überschreibbar, ohne den Prozentsatz zu verbiegen (§5.3).
 */
class ChampionshipStandard extends Model
{
    protected $fillable = [
        'championship_id',
        'stroke_type_id',
        'distance',
        'gender',
        'sport_class',
        'mqs_centiseconds',
        'met_centiseconds',
        'obsv_percent',
        'obsv_centiseconds',
        'obsv_is_manual',
        'notes',
    ];

    protected $casts = [
        'distance' => 'integer',
        'mqs_centiseconds' => 'integer',
        'met_centiseconds' => 'integer',
        'obsv_percent' => 'float',
        'obsv_centiseconds' => 'integer',
        'obsv_is_manual' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    public function strokeType(): BelongsTo
    {
        return $this->belongsTo(StrokeType::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Ist ein Prozentsatz festgelegt? Bewusst ein echter Null-Vergleich:
     * obsv_percent = 0.0 ist festgelegt (MQS übernommen), null ist offen ([Q3]).
     * Ein truthy-Check würde die 0 fälschlich als "offen" behandeln.
     */
    public function hasObsvPercent(): bool
    {
        return $this->obsv_percent !== null;
    }

    public function isObsvOpen(): bool
    {
        return ! $this->hasObsvPercent();
    }

    public function isObsvManual(): bool
    {
        return $this->obsv_is_manual;
    }

    /**
     * Die anzuwendende ÖBSV-Norm in Hundertstelsekunden, oder null, wenn keine
     * festgelegt ist. Fällt bewusst NICHT auf die MQS zurück — ob eine ÖBSV-Norm
     * existiert, ist eine eigene Information (§7.2 unterscheidet mqs_met von obsv_met).
     */
    public function effectiveObsvTime(): ?int
    {
        return $this->obsv_centiseconds;
    }

    public function getFormattedMqsAttribute(): ?string
    {
        return $this->mqs_centiseconds === null
            ? null
            : TimeParser::display($this->mqs_centiseconds);
    }

    public function getFormattedMetAttribute(): ?string
    {
        return $this->met_centiseconds === null
            ? null
            : TimeParser::display($this->met_centiseconds);
    }

    public function getFormattedObsvAttribute(): ?string
    {
        return $this->obsv_centiseconds === null
            ? null
            : TimeParser::display($this->obsv_centiseconds);
    }

    public function getDisplayNameAttribute(): string
    {
        $stroke = $this->strokeType?->name_de ?? '';

        return "{$this->distance}m $stroke ($this->gender/$this->sport_class)";
    }
}

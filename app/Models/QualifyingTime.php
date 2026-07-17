<?php

namespace App\Models;

use App\Support\TimeParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualifyingTime extends Model
{
    public const string SOURCE_MANUAL = 'MANUAL';

    public const string SOURCE_CALCULATED = 'CALCULATED';

    protected $fillable = [
        'qualifying_time_list_id',
        'stroke_type_id',
        'distance',
        'gender',
        'sport_class',
        'value_centiseconds',
        'source',
    ];

    protected $attributes = [
        'source' => self::SOURCE_MANUAL,
    ];

    protected $casts = [
        'distance' => 'integer',
        'value_centiseconds' => 'integer',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function qualifyingTimeList(): BelongsTo
    {
        return $this->belongsTo(QualifyingTimeList::class);
    }

    public function strokeType(): BelongsTo
    {
        return $this->belongsTo(StrokeType::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getFormattedValueAttribute(): ?string
    {
        if ($this->value_centiseconds === null) {
            return null;
        }

        return TimeParser::display($this->value_centiseconds);
    }

    public function getDisplayNameAttribute(): string
    {
        $stroke = $this->strokeType?->name_de ?? '';

        return "{$this->distance}m $stroke ($this->gender/$this->sport_class)";
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }
}

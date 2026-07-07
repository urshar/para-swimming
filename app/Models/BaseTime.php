<?php

namespace App\Models;

use App\Support\TimeParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BaseTime extends Model
{
    public const string TYPE_MANUAL = 'MANUAL';

    public const string TYPE_CALCULATED = 'CALCULATED';

    public const string TYPE_NOT_APPLICABLE = 'NOT_APPLICABLE';

    /** @used-by BaseTimeControllerTest zur Validierung erlaubter value_type-Werte */
    public const array VALUE_TYPES = [
        self::TYPE_MANUAL,
        self::TYPE_CALCULATED,
        self::TYPE_NOT_APPLICABLE,
    ];

    protected $fillable = [
        'base_time_version_id',
        'base_time_category_id',
        'base_time_discipline_id',
        'base_time_sport_class_id',
        'value_centiseconds',
        'value_type',
    ];

    protected $attributes = [
        'value_type' => self::TYPE_MANUAL,
    ];

    protected $casts = [
        'value_centiseconds' => 'integer',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function version(): BelongsTo
    {
        return $this->belongsTo(BaseTimeVersion::class, 'base_time_version_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BaseTimeCategory::class, 'base_time_category_id');
    }

    public function discipline(): BelongsTo
    {
        return $this->belongsTo(BaseTimeDiscipline::class, 'base_time_discipline_id');
    }

    public function sportClass(): BelongsTo
    {
        return $this->belongsTo(BaseTimeSportClass::class, 'base_time_sport_class_id');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function isEditable(): bool
    {
        return $this->value_type === self::TYPE_MANUAL;
    }

    public function getFormattedValueAttribute(): ?string
    {
        if ($this->value_type === self::TYPE_NOT_APPLICABLE) {
            return null;
        }

        return TimeParser::display($this->value_centiseconds);
    }
}

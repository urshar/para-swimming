<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SwimEvent extends Model
{
    protected $fillable = [
        'meet_id',
        'stroke_type_id',
        'event_number',
        'session_number',
        'gender',
        'round',
        'lenex_status',
        'distance',
        'relay_count',
        'technique',
        'style_code',
        'style_name',
        'sport_classes',
        'prev_event_id',
        'timing',
        'lenex_event_id',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function meet(): BelongsTo
    {
        return $this->belongsTo(Meet::class);
    }

    public function strokeType(): BelongsTo
    {
        return $this->belongsTo(StrokeType::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    public function previousEvent(): BelongsTo
    {
        return $this->belongsTo(SwimEvent::class, 'prev_event_id');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        $relay = $this->relay_count > 1 ? (' '.$this->relay_count.'x') : '';
        $stroke = $this->strokeType?->name_de ?? $this->style_name ?? '';

        return $relay.$this->distance.'m '.$stroke;
    }

    public function isRelay(): bool
    {
        return $this->relay_count > 1;
    }
}

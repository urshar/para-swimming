<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class SwimRecord extends Model
{
    protected $fillable = [
        'stroke_type_id',
        'nation_id',
        'athlete_id',
        'result_id',
        'superseded_by_id',
        'supersedes_id',
        'record_type',
        'sport_class',
        'gender',
        'course',
        'distance',
        'relay_count',
        'swim_time',
        'record_status',
        'is_current',
        'set_date',
        'meet_name',
        'meet_city',
        'meet_course',
        'comment',
    ];

    protected $casts = [
        'set_date' => 'date',
        'is_current' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function strokeType(): BelongsTo
    {
        return $this->belongsTo(StrokeType::class);
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    /** Der neuere Rekord der diesen ersetzt hat */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(SwimRecord::class, 'superseded_by_id');
    }

    /** Der ältere Rekord den dieser ersetzt hat */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(SwimRecord::class, 'supersedes_id');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(RecordSplit::class)->orderBy('distance');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeHistory($query)
    {
        return $query->where('is_current', false);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('record_type', $type);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getFormattedSwimTimeAttribute(): string
    {
        return Entry::formatTime($this->swim_time);
    }

    /**
     * Gibt die vollständige Rekord-Kette zurück (ältester zuerst).
     * Traversiert über supersedes_id rückwärts bis zum ersten Rekord.
     */
    public function getHistoryChain(): Collection
    {
        $chain = collect([$this]);
        $current = $this;

        while ($current->supersedes_id) {
            $current = $current->supersedes()->first();
            if (! $current) {
                break;
            }
            $chain->prepend($current);
        }

        return $chain;
    }

    /**
     * Wird aufgerufen wenn dieser Rekord von einem neueren überboten wird.
     * Setzt is_current = false, record_status und superseded_by_id.
     */
    public function markAsSupersededBy(SwimRecord $newRecord): void
    {
        $this->update([
            'is_current' => false,
            'superseded_by_id' => $newRecord->id,
            'record_status' => match ($this->record_status) {
                'APPROVED' => 'APPROVED.HISTORY',
                'PENDING' => 'PENDING.HISTORY',
                default => $this->record_status,
            },
        ]);
    }
}

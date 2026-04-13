<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RelayEntry extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'meet_id',
        'swim_event_id',
        'club_id',
        'relay_class',
        'entry_time',
        'entry_time_code',
        'entry_course',
        'status',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function meet(): BelongsTo
    {
        return $this->belongsTo(Meet::class);
    }

    public function swimEvent(): BelongsTo
    {
        return $this->belongsTo(SwimEvent::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * Nur bestätigte Staffelmeldungen.
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->where('status', 'confirmed');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Nur ausstehende Staffelmeldungen.
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    /**
     * Gibt true zurück, wenn diese Meldung vollständig besetzt ist
     * (Anzahl Mitglieder = relay_count des Events).
     */
    public function isComplete(): bool
    {
        $required = $this->swimEvent?->relay_count ?? 4;

        return $this->members()->count() === $required;
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function members(): HasMany
    {
        return $this->hasMany(RelayEntryMember::class)->orderBy('position');
    }

    /**
     * Gibt die Staffelklasse basierend auf den Mitglieder-Sportklassen zurück.
     * Delegiert an RelayClassValidator — wird im Service aufgerufen.
     */
    public function getMemberCountAttribute(): int
    {
        return $this->members()->count();
    }
}

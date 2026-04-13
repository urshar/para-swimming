<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RelayEntryMember extends Model
{
    protected $fillable = [
        'relay_entry_id',
        'athlete_id',
        'position',
        'sport_class',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function relayEntry(): BelongsTo
    {
        return $this->belongsTo(RelayEntry::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Gibt die effektive Sportklasse zurück:
     * entweder direkt gespeichert oder aus AthleteSportClass ermittelt.
     * Benötigt den Stroke-Kontext für die richtige Kategorie (S/SB/SM).
     */
    public function resolvedSportClass(?string $lenexStrokeCode = null): ?string
    {
        if ($this->sport_class) {
            return $this->sport_class;
        }

        if (! $this->athlete) {
            return null;
        }

        $category = match ($lenexStrokeCode) {
            'BREAST' => 'SB',
            'MEDLEY', 'IMRELAY' => 'SM',
            default => 'S',
        };

        $sportClass = $this->athlete->sportClasses
            ->firstWhere('category', $category);

        if (! $sportClass) {
            return null;
        }

        return $category.$sportClass->class_number;
    }
}

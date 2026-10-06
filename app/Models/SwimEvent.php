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
        'fee_cents',
        'technique',
        'style_code',
        'style_name',
        'sport_classes',
        'is_scored',
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

    public function relayResults(): HasMany
    {
        return $this->hasMany(RelayResult::class);
    }

    /** Wertungsgruppen des Bewerbs in ihrer Reihenfolge (LENEX AGEGROUPs). */
    public function scoringGroups(): HasMany
    {
        return $this->hasMany(ScoringGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    public function previousEvent(): BelongsTo
    {
        return $this->belongsTo(SwimEvent::class, 'prev_event_id');
    }

    /**
     * Setzt sport_classes (Grundlage der Meldeberechtigung) auf die Vereinigung der Klassen aller Wertungsgruppen.
     * Umfasst eine Gruppe alle Klassen (leer), bleibt sport_classes leer (= keine Einschränkung). Ohne Gruppen bleibt
     * der bisherige Wert stehen.
     */
    public function syncSportClassesFromGroups(): void
    {
        $groups = $this->scoringGroups()->get();
        if ($groups->isEmpty()) {
            return;
        }

        $open = $groups->contains(fn (ScoringGroup $g): bool => $g->classNumbers() === []);
        $classes = $groups->flatMap(fn (ScoringGroup $g): array => $g->classNumbers())->unique()->sort()->values();

        $this->update(['sport_classes' => $open ? null : $classes->implode(' ')]);
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

    protected function casts(): array
    {
        return [
            // false = Rahmenbewerb (z. B. Schnupperbewerb): wird nicht gewertet, Ergebnisse/Meldungen nicht importiert.
            'is_scored' => 'boolean',
        ];
    }
}

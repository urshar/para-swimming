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

    /**
     * Geschlechts-Kategorie der Staffel aus den tatsächlichen Mitgliedern — nicht aus
     * swim_events.gender, das nur die Zulassung des Bewerbs angibt (z. B. "A" = offen für
     * alle Geschlechter), nicht die tatsächliche Team-Zusammensetzung.
     *
     * Regel: rein männlich → Herren ('M'), rein weiblich → Damen ('F'), jede andere
     * Kombination bleibt Herren ('M') — außer bei exakt zwei Männern und zwei Frauen,
     * das gilt als Mixed ('X'). Gibt null zurück, wenn noch keine Mitglieder mit bekanntem
     * Geschlecht zugeordnet sind.
     *
     * Erwartet $members bereits geladen (Blade-Listen laden die Relation ohnehin).
     */
    public function teamGender(): ?string
    {
        $genders = $this->members
            ->map(fn (RelayEntryMember $m) => $m->athlete?->gender)
            ->filter();

        if ($genders->isEmpty()) {
            return null;
        }

        $male = $genders->filter(fn ($g) => $g === 'M')->count();
        $female = $genders->filter(fn ($g) => $g === 'F')->count();

        if ($male === 2 && $female === 2) {
            return 'X';
        }

        return $female > 0 && $male === 0 ? 'F' : 'M';
    }
}

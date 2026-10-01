<?php

namespace App\Models;

use App\Support\TimeParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Staffelmeldung eines Vereins für einen Staffelbewerb.
 *
 * @property int $id
 * @property int $meet_id
 * @property int $swim_event_id
 * @property int $club_id
 * @property string|null $name Frei vergebener Staffelname; leer = automatischer Name (App\Support\RelayNames)
 * @property string|null $relay_class
 * @property int|null $entry_time
 * @property string|null $entry_time_code
 * @property string|null $entry_course
 * @property string $status
 * @property bool $is_late_entry Nach Meldeschluss neu angelegt (Nachmeldegebühr LATEENTRY.RELAY)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $formatted_entry_time
 * @property-read int $member_count
 * @property-read Meet|null $meet
 * @property-read SwimEvent|null $swimEvent
 * @property-read Club|null $club
 * @property-read Collection<int, RelayEntryMember> $members
 */
class RelayEntry extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'meet_id',
        'swim_event_id',
        'club_id',
        'name',
        'relay_class',
        'entry_time',
        'entry_time_code',
        'entry_course',
        'status',
        'is_late_entry',
    ];

    protected $casts = [
        'is_late_entry' => 'boolean',
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

    /**
     * Meldezeit formatiert (wie Entry): numerische Zeit als "MM:SS.hh", sonst der
     * Code (NT/NS/WO) bzw. "NT", wenn keine Zeit hinterlegt ist.
     */
    public function getFormattedEntryTimeAttribute(): string
    {
        if (! $this->entry_time) {
            return $this->entry_time_code ?? 'NT';
        }

        return TimeParser::display($this->entry_time);
    }

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

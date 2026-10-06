<?php

namespace App\Models;

use App\Support\TimeParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * RelayResult — Ergebnis einer Staffel in einem Staffelbewerb.
 *
 * gender ist das Geschlecht der Mannschaft (M = Herren, F = Damen, X = Mixed), nicht das des Bewerbs.
 * Regel (ÖBSV): Damen = nur Frauen, Mixed = gleich viele Frauen und Männer (bei 4er-Staffeln 2 + 2), jede andere
 * Zusammensetzung (z. B. 3 Herren + 1 Dame) wird in der Herrenwertung gewertet, kann aber keinen Rekord aufstellen.
 *
 * @property int $id
 * @property int $meet_id
 * @property int $swim_event_id
 * @property int $club_id
 * @property int|null $relay_number
 * @property string|null $name
 * @property string $gender
 * @property string|null $relay_class
 * @property int|null $swim_time
 * @property string|null $status
 * @property int|null $place
 * @property int|null $points
 * @property string|null $lenex_result_id
 * @property-read Collection<int, RelayResultMember> $members
 * @property-read Collection<int, RelayResultSplit> $splits
 */
class RelayResult extends Model
{
    protected $fillable = [
        'meet_id',
        'swim_event_id',
        'club_id',
        'relay_number',
        'name',
        'gender',
        'relay_class',
        'swim_time',
        'status',
        'place',
        'points',
        'heat',
        'lane',
        'comment',
        'is_world_record',
        'is_european_record',
        'is_national_record',
        'is_junior_record',
        'is_regional_record',
        'is_regional_junior_record',
        'lenex_result_id',
    ];

    protected $casts = [
        'is_world_record' => 'boolean',
        'is_european_record' => 'boolean',
        'is_national_record' => 'boolean',
        'is_junior_record' => 'boolean',
        'is_regional_record' => 'boolean',
        'is_regional_junior_record' => 'boolean',
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

    public function members(): HasMany
    {
        return $this->hasMany(RelayResultMember::class)->orderBy('position');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(RelayResultSplit::class)->orderBy('distance');
    }

    // ── Geschlecht und Zusammensetzung ───────────────────────────────────────

    /**
     * Staffel-Geschlecht aus den Geschlechtern der Mitglieder: nur Frauen = F, gleich viele Frauen und Männer = X,
     * sonst M (auch bei Damenbeteiligung, z. B. 3 + 1 oder 1 + 3).
     *
     * @param  iterable<string|null>  $genders
     */
    public static function genderFromMembers(iterable $genders): string
    {
        $genders = collect($genders)->filter()->values();
        $female = $genders->filter(fn (string $g): bool => $g === 'F')->count();
        $male = $genders->filter(fn (string $g): bool => $g === 'M')->count();

        return match (true) {
            $female > 0 && $male === 0 => 'F',
            $female > 0 && $female === $male => 'X',
            default => 'M',
        };
    }

    /**
     * Ob die Zusammensetzung zum Staffel-Geschlecht passt und damit rekordfähig ist: Herren nur Männer, Damen nur
     * Frauen, Mixed gleich viele von beiden, und alle Positionen mit bekanntem Geschlecht besetzt. Eine Herrenstaffel
     * mit Damenbeteiligung bleibt ein gültiges Ergebnis, stellt aber keinen Rekord auf.
     */
    public function hasRecordComposition(): bool
    {
        $required = $this->swimEvent?->relay_count ?? 4;
        $genders = $this->members->map(fn (RelayResultMember $m): ?string => $m->memberGender())->filter();

        if ($this->members->count() !== $required || $genders->count() !== $required) {
            return false;
        }

        return self::genderFromMembers($genders) === $this->gender
            && ($this->gender !== 'M' || $genders->every(fn (string $g): bool => $g === 'M'));
    }

    // ── Anzeige ───────────────────────────────────────────────────────────────

    public function getFormattedSwimTimeAttribute(): string
    {
        if (! $this->swim_time) {
            return $this->status ?? '—';
        }

        return TimeParser::display($this->swim_time);
    }

    /** "Herren", "Damen" oder "Mixed". */
    public function getGenderLabelAttribute(): string
    {
        return match ($this->gender) {
            'F' => 'Damen',
            'X' => 'Mixed',
            default => 'Herren',
        };
    }

    public function hasRecords(): bool
    {
        return $this->is_world_record
            || $this->is_european_record
            || $this->is_national_record
            || $this->is_junior_record
            || $this->is_regional_record
            || $this->is_regional_junior_record;
    }

    /** Anzeigename: eigener Name, sonst Verein mit Mannschaftsnummer ab der zweiten Staffel. */
    public function getDisplayNameAttribute(): string
    {
        if ($this->name) {
            return $this->name;
        }

        $club = $this->club?->display_name ?? '–';

        return $this->relay_number && $this->relay_number > 1 ? $club.' '.$this->relay_number : $club;
    }
}

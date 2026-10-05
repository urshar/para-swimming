<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ScoringGroup — Wertungsgruppe eines Bewerbs (LENEX AGEGROUP).
 *
 * Bestimmt, welche Ergebnisse gemeinsam gewertet werden: Geschlecht, Sportklassen (Nummern; Kategorie aus der Lage,
 * bei Staffeln die Staffelklasse), Alter. Die Zuordnung berechnet App\Services\ScoringGroupService.
 *
 * @property int $id
 * @property int $swim_event_id
 * @property string $name
 * @property string $gender
 * @property string|null $sport_classes
 * @property int|null $age_min
 * @property int|null $age_max
 * @property string|null $title
 * @property int $sort_order
 * @property string|null $lenex_agegroup_id
 */
class ScoringGroup extends Model
{
    public const string TITLE_STATE = 'OSTM';

    public const string TITLE_NATIONAL = 'OM';

    /** Meisterschaftstitel für Auswahl und Anzeige. */
    public const array TITLES = [
        self::TITLE_STATE => 'Österr. Staatsmeisterschaft',
        self::TITLE_NATIONAL => 'Österr. Meisterschaft',
    ];

    protected $fillable = [
        'swim_event_id',
        'name',
        'gender',
        'sport_classes',
        'age_min',
        'age_max',
        'title',
        'sort_order',
        'lenex_agegroup_id',
    ];

    protected $casts = [
        'age_min' => 'integer',
        'age_max' => 'integer',
        'sort_order' => 'integer',
    ];

    public function swimEvent(): BelongsTo
    {
        return $this->belongsTo(SwimEvent::class);
    }

    /**
     * Sportklassen als Nummern; leer = alle Klassen.
     *
     * @return list<int>
     */
    public function classNumbers(): array
    {
        return self::parseClassNumbers($this->sport_classes);
    }

    /**
     * Ob ein Ergebnis mit diesen Merkmalen in die Gruppe fällt. Unbekanntes Alter passt nur, wenn die Gruppe keine
     * Altersgrenze hat; eine unbekannte Klasse nur, wenn die Gruppe alle Klassen umfasst.
     */
    public function matches(?string $gender, ?int $classNumber, ?int $age): bool
    {
        if ($this->gender !== 'A' && $this->gender !== $gender) {
            return false;
        }

        $classes = $this->classNumbers();
        if ($classes !== [] && ! in_array($classNumber, $classes, true)) {
            return false;
        }

        if ($this->age_min !== null || $this->age_max !== null) {
            if ($age === null) {
                return false;
            }
            if (($this->age_min !== null && $age < $this->age_min) || ($this->age_max !== null && $age > $this->age_max)) {
                return false;
            }
        }

        return true;
    }

    /** Anzeigename mit Geschlecht, z. B. "Damen – ÖSTM: S01 - S08". */
    public function label(): string
    {
        $gender = match ($this->gender) {
            'M' => 'Herren',
            'F' => 'Damen',
            'X' => 'Mixed',
            default => null,
        };

        return $gender ? $gender.' – '.$this->name : $this->name;
    }

    /**
     * "1,2,3", "1 2 3", "S1 S2" oder "SB4" → [1, 2, 3] bzw. [4]; dedupliziert und sortiert.
     *
     * @return list<int>
     */
    public static function parseClassNumbers(?string $classes): array
    {
        return collect(preg_split('/[\s,;]+/', trim((string) $classes)))
            ->map(fn (string $c): string => preg_replace('/\D+/', '', $c))
            ->filter(fn (string $c): bool => $c !== '')
            ->map(fn (string $c): int => (int) $c)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}

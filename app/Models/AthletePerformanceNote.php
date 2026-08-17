<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notiz zur Leistungsentwicklung eines Athleten (Spec "WPS Rankings" §7.5).
 */
class AthletePerformanceNote extends Model
{
    public const string CATEGORY_ILLNESS = 'illness';

    public const string CATEGORY_INJURY = 'injury';

    public const string CATEGORY_RECLASSIFICATION = 'reclassification';

    public const string CATEGORY_TRAINING = 'training';

    public const string CATEGORY_CONDITIONS = 'conditions';

    public const string CATEGORY_OTHER = 'other';

    /** @used-by WpsRankingsNotesTest zur Validierung erlaubter category-Werte */
    public const array CATEGORIES = [
        self::CATEGORY_ILLNESS,
        self::CATEGORY_INJURY,
        self::CATEGORY_RECLASSIFICATION,
        self::CATEGORY_TRAINING,
        self::CATEGORY_CONDITIONS,
        self::CATEGORY_OTHER,
    ];

    /**
     * Kategorien mit Gesundheitsbezug.
     *
     * Sie sind der Grund, warum Notizen nicht verbandsweit sichtbar sind (§7.5): Krankheit
     * und Verletzung sind Gesundheitsangaben und gehen nur den eigenen Verein und die
     * Verbandsverwaltung etwas an.
     */
    public const array HEALTH_CATEGORIES = [
        self::CATEGORY_ILLNESS,
        self::CATEGORY_INJURY,
    ];

    protected $fillable = [
        'athlete_id',
        'result_id',
        'noted_on',
        'category',
        'note',
        'created_by',
    ];

    protected $casts = [
        'noted_on' => 'date',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Notizen im Zeitraum — Grenztage zählen mit. */
    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('noted_on', [$from, "$to 23:59:59"]);
    }

    public function isHealthRelated(): bool
    {
        return in_array($this->category, self::HEALTH_CATEGORIES, true);
    }

    /**
     * Beschriftungen aller Kategorien.
     *
     * Statisch, damit das Auswahlfeld sie nutzen kann, ohne für jede Zeile ein Modell zu
     * erzeugen.
     *
     * @return array<string, string>
     */
    public static function categoryLabels(): array
    {
        return [
            self::CATEGORY_ILLNESS => 'Krankheit',
            self::CATEGORY_INJURY => 'Verletzung',
            self::CATEGORY_RECLASSIFICATION => 'Umklassifizierung',
            self::CATEGORY_TRAINING => 'Trainingsumfang',
            self::CATEGORY_CONDITIONS => 'Wettkampfbedingungen',
            self::CATEGORY_OTHER => 'Sonstiges',
        ];
    }

    public function categoryLabel(): string
    {
        return self::categoryLabels()[$this->category] ?? 'Sonstiges';
    }

    /**
     * Farbe der Kennzeichnung.
     *
     * Krankheit und Verletzung in Rot, Umklassifizierung in Bernstein: Letztere erklärt einen
     * Punktesprung, der keine Leistungsentwicklung ist, und verdient dieselbe Aufmerksamkeit
     * wie eine geschätzte Zeit.
     */
    public function categoryColour(): string
    {
        return match ($this->category) {
            self::CATEGORY_ILLNESS, self::CATEGORY_INJURY => 'red',
            self::CATEGORY_RECLASSIFICATION => 'amber',
            self::CATEGORY_TRAINING => 'blue',
            default => 'zinc',
        };
    }
}

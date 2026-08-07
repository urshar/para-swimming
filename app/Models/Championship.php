<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Internationale Meisterschaft mit Qualifikationszeitraum (Spec "WPS Qualification" §5.1).
 *
 * Jede Ausgabe ist ein eigener Datensatz — Meisterschaften und ihre Normen werden
 * nicht überschrieben, damit vergangene Entscheidungen nachvollziehbar bleiben (§9.3).
 */
class Championship extends Model
{
    public const string TYPE_EC = 'EC';

    public const string TYPE_WC = 'WC';

    public const string TYPE_PARALYMPICS = 'PARALYMPICS';

    public const string TYPE_OTHER = 'OTHER';

    /** @used-by WpsQualificationPhase1Test zur Validierung erlaubter type-Werte */
    public const array TYPES = [
        self::TYPE_EC,
        self::TYPE_WC,
        self::TYPE_PARALYMPICS,
        self::TYPE_OTHER,
    ];

    public const string COURSE_LCM = 'LCM';

    public const string COURSE_SCM = 'SCM';

    /** @used-by WpsQualificationPhase1Test zur Validierung erlaubter course-Werte */
    public const array COURSES = [
        self::COURSE_LCM,
        self::COURSE_SCM,
    ];

    protected $fillable = [
        'name',
        'short_name',
        'type',
        'year',
        'course',
        'qualification_start',
        'qualification_end',
        'source',
        'notes',
        'is_active',
    ];

    /**
     * Defaults auch im Model, nicht nur in der Migration.
     *
     * Ein DB-Default füllt die Spalte beim INSERT, wird aber nicht in die im
     * Speicher liegende Instanz zurückgelesen — `is_active` wäre sonst bis zum
     * ersten refresh() null und jede Prüfung darauf falsch.
     */
    protected $attributes = [
        'course' => self::COURSE_LCM,
        'is_active' => true,
    ];

    protected $casts = [
        'year' => 'integer',
        'qualification_start' => 'date',
        'qualification_end' => 'date',
        'is_active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function standards(): HasMany
    {
        return $this->hasMany(ChampionshipStandard::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Liegt das Datum innerhalb des Qualifikationszeitraums? Beide Grenztage zählen mit.
     *
     * Der Vergleich läuft bewusst über Datumsstrings im Format Y-m-d statt über
     * DB-Funktionen — die Auswertung findet in PHP statt, und für Queries gilt im
     * Projekt whereBetween() mit expliziten Datumsstrings (SQLite-Portabilität).
     */
    public function isWithinQualificationPeriod(string $date): bool
    {
        $tag = substr(trim($date), 0, 10);

        return $tag >= $this->qualification_start->format('Y-m-d')
            && $tag <= $this->qualification_end->format('Y-m-d');
    }

    /**
     * Grenzen des Qualifikationszeitraums als Datumsstrings — für whereBetween()
     * in den auswertenden Services ab Phase 4.
     *
     * @return array{0: string, 1: string}
     */
    public function qualificationPeriodBounds(): array
    {
        return [
            $this->qualification_start->format('Y-m-d'),
            $this->qualification_end->format('Y-m-d'),
        ];
    }

    public function isLongCourse(): bool
    {
        return $this->course === self::COURSE_LCM;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->short_name ?? $this->name;
    }
}

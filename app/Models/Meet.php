<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

class Meet extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'city',
        'nation_id',
        'course',
        'start_date',
        'end_date',
        'organizer',
        'altitude',
        'timing',
        'entry_type',
        'lenex_status',
        'is_open',
        'wps_approved',
        'wps_approved_note',
        'swrid',
        'lenex_meet_id',
        'entries_deadline',
        'cup_id',
        'qualifying_time_list_id',
        'livetiming_url',
        'is_published',
    ];

    /**
     * Defaults auch im Model, nicht nur in der Migration: Ein DB-Default füllt die Spalte
     * beim INSERT, wird aber nicht in die im Speicher liegende Instanz zurückgelesen.
     */
    protected $attributes = [
        'wps_approved' => false,
        'is_published' => false,
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_open' => 'boolean',
        'wps_approved' => 'boolean',
        'entries_deadline' => 'date',
        'is_published' => 'boolean',
    ];

    /**
     * Jahre, für die Veranstaltungen erfasst sind — absteigend.
     *
     * Bewusst in PHP aus den Startdaten abgeleitet statt per YEAR()/strftime(),
     * damit die Abfrage auf MySQL und SQLite gleich funktioniert.
     *
     * @return SupportCollection<int, int>
     */
    public static function yearsWithMeets(): SupportCollection
    {
        return self::query()
            ->whereNotNull('start_date')
            ->orderByDesc('start_date')
            ->pluck('start_date')
            ->map(fn ($date): int => (int) $date->year)
            ->unique()
            ->values();
    }

    // ── Relationen ────────────────────────────────────────────────────────────

    /**
     * Von World Para Swimming sanktionierte Wettkämpfe.
     *
     * Nur deren Zeiten gelten als Qualifikationsnachweis (Spec "WPS Qualification" §7.1).
     */
    public function scopeWpsApproved(Builder $query): Builder
    {
        return $query->where('wps_approved', true);
    }

    /** Öffentlich sichtbare Veranstaltungen (Spec public-frontend §4.2). */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function cup(): BelongsTo
    {
        return $this->belongsTo(Cup::class);
    }

    /**
     * Richtzeitenliste, falls dieses Meet als ÖSTM & ÖM-Veranstaltung des
     * jeweiligen Wettkampfjahres markiert ist (Modul "Richtzeiten ÖSTM & ÖM").
     */
    public function qualifyingTimeList(): BelongsTo
    {
        return $this->belongsTo(QualifyingTimeList::class);
    }

    /** Ermittelte Qualifikationen für dieses ÖSTM & ÖM-Meet (Phase 4/5/6). */
    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class);
    }

    /**
     * Punktesysteme, die für diese Veranstaltung berechnet werden.
     *
     * Der Pivot trägt zusätzlich wps_point_version_id: damit kann für ein einzelnes Meet eine
     * andere als die nach Wettkampfdatum ermittelte WPS-Version erzwungen werden.
     */
    public function pointSystems(): BelongsToMany
    {
        return $this->belongsToMany(PointSystem::class, 'meet_point_system')
            ->withPivot('wps_point_version_id')
            ->withTimestamps();
    }

    public function clubs(): BelongsToMany
    {
        return $this->belongsToMany(Club::class, 'meet_club');
    }

    public function swimEvents(): HasMany
    {
        return $this->hasMany(SwimEvent::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function relayEntries(): HasMany
    {
        return $this->hasMany(RelayEntry::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    /** Dokumente dieser Veranstaltung (Ausschreibung, Meldeliste, ...) — Spec public-frontend §4.1. */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getDateRangeAttribute(): string
    {
        if (! $this->end_date || $this->start_date->eq($this->end_date)) {
            return $this->start_date->format('d.m.Y');
        }

        return $this->start_date->format('d.m.Y').' – '.$this->end_date->format('d.m.Y');
    }

    /**
     * Anzahl der eindeutigen Athleten mit mindestens einer Einzel- oder Staffelmeldung
     * ODER einem Ergebnis zu dieser Veranstaltung. Das Ergebnis zählt bewusst mit: bei
     * per LENEX importierten Meets liegen mitunter nur Ergebnisse ohne zugehörige
     * Meldungen vor (das LENEX enthielt keinen Meldungen-Abschnitt) — ohne diese
     * Ergänzung stünde hier trotz hunderter Ergebnisse fälschlich 0. Portabel (kein
     * DISTINCT-COUNT über einen JOIN mit MySQL-only-Funktionen), damit die Query auf
     * SQLite (Tests) und MySQL (Dev/Prod) gleich läuft.
     */
    public function participantsCount(): int
    {
        $individualAthleteIds = $this->entries()->pluck('athlete_id');

        $relayAthleteIds = RelayEntryMember::query()
            ->whereIn('relay_entry_id', $this->relayEntries()->pluck('id'))
            ->pluck('athlete_id');

        $resultAthleteIds = $this->results()->pluck('athlete_id');

        return $individualAthleteIds->merge($relayAthleteIds)->merge($resultAthleteIds)->unique()->count();
    }

    /**
     * Anzahl der Vereine mit mindestens einer Einzel- oder Staffelmeldung ODER einem
     * Ergebnis zu dieser Veranstaltung. Bewusst NICHT $this->clubs() (meet_club-Pivot) —
     * die wird nur beim LENEX-Import befüllt, nicht beim regulären Melden über die
     * Meldungen-Oberfläche, und wäre bei einem UI-erfassten Meet trotz vorhandener
     * Meldungen fälschlich leer. Ergebnisse fließen aus demselben Grund wie bei
     * participantsCount() mit ein (LENEX-Importe ohne Meldungen-Abschnitt).
     */
    public function participatingClubsCount(): int
    {
        $individualClubIds = $this->entries()->pluck('club_id');
        $relayClubIds = $this->relayEntries()->pluck('club_id');
        $resultClubIds = $this->results()->pluck('club_id');

        return $individualClubIds->merge($relayClubIds)->merge($resultClubIds)->unique()->count();
    }

    /** Ob für diese Veranstaltung WPS-Punkte berechnet werden sollen. */
    public function hasWpsPointsEnabled(): bool
    {
        return $this->pointSystems()
            ->where('code', PointSystem::CODE_WPS)
            ->exists();
    }

    public function isDeadlinePassed(): bool
    {
        if (! $this->entries_deadline) {
            return false;
        }

        return Carbon::today()->gt($this->entries_deadline);
    }

    public function hasDeadline(): bool
    {
        return $this->entries_deadline !== null;
    }
}

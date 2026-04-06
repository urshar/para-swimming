<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Athlete extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'club_id',
        'nation_id',
        'first_name',
        'last_name',
        'name_prefix',
        'birth_date',
        'gender',
        'license',
        'license_ipc',
        'status',
        'disability_type',
        'swrid',
        // Neu:
        'is_active',
        'notes',
        'email',
        'phone',
        'address_street',
        'address_city',
        'address_zip',
        'address_country',
        'level',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'is_active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function sportClasses(): HasMany
    {
        return $this->hasMany(AthleteSportClass::class);
    }

    public function exceptions(): BelongsToMany
    {
        return $this->belongsToMany(ExceptionCode::class, 'athlete_exceptions')
            ->withPivot('category', 'note')
            ->withTimestamps();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    public function swimRecords(): HasMany
    {
        return $this->hasMany(SwimRecord::class);
    }

    // ── Neue Relationen ───────────────────────────────────────────────────────

    /**
     * Aktuell aktive Vereinsmitgliedschaft.
     */
    public function activeClubHistory(): HasMany
    {
        return $this->hasMany(AthleteClubHistory::class)->where('is_active', true);
    }

    /**
     * Classifications-History (neueste zuerst).
     */
    public function classifications(): HasMany
    {
        return $this->hasMany(AthleteClassification::class)->orderByDesc('classified_at');
    }

    /**
     * Letzte Klassifikation.
     */
    public function latestClassification(): HasMany
    {
        return $this->hasMany(AthleteClassification::class)
            ->orderByDesc('classified_at')
            ->limit(1);
    }

    /**
     * Level-History (neueste zuerst).
     */
    public function levelHistory(): HasMany
    {
        return $this->hasMany(AthleteLevelHistory::class)->orderByDesc('changed_at');
    }

    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->name_prefix,
            $this->last_name,
            $this->first_name,
        ]);

        return implode(' ', $parts);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        return trim($this->name_prefix.' '.$this->last_name.', '.$this->first_name, ' ,');
    }

    /** Sport-Klasse für eine bestimmte Kategorie (S / SB / SM) */
    public function getSportClass(string $category): ?AthleteSportClass
    {
        return $this->sportClasses->firstWhere('category', $category);
    }

    /** Kurzdarstellung aller Sport-Klassen z.B. "S4 / SB3 / SM4" */
    public function getSportClassesDisplayAttribute(): string
    {
        return $this->sportClasses
            ->sortBy('category')
            ->pluck('sport_class')
            ->join(' / ');
    }

    /**
     * Welchem Verein gehörte der Athlet an einem bestimmten Datum an?
     * Nützlich für Rekordprüfungen (set_date).
     */
    public function clubAtDate(Carbon|string $date): ?Club
    {
        $date = Carbon::parse($date)->toDateString();

        $entry = $this->clubHistory()
            ->where('joined_at', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('left_at')->orWhere('left_at', '>=', $date);
            })
            ->orderByDesc('joined_at')
            ->first();

        return $entry?->club;
    }

    /**
     * Vollständige Vereins-History (ältester zuerst).
     */
    public function clubHistory(): HasMany
    {
        return $this->hasMany(AthleteClubHistory::class)->orderBy('joined_at');
    }
}

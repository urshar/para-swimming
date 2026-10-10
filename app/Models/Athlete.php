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

    /** Behinderungsgruppen (Kürzel wie im Splash Team Manager und in der Statistik). */
    const array DISABILITY_GROUPS = [
        'PI' => 'Körperliche Beeinträchtigung',
        'VI' => 'Sehbeeinträchtigung',
        'MI' => 'Intellektuelle Beeinträchtigung',
        'HI' => 'Hörbeeinträchtigung',
        'T21' => 'Down-Syndrom (Trisomie 21)',
    ];

    /** Untergruppen der körperlichen Beeinträchtigung (PI) — nur für die Statistik. */
    const array DISABILITY_SUBGROUPS = [
        'A' => 'Amputation',
        'C' => 'Cerebralparese',
        'R' => 'Rollstuhl',
    ];

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
        'disability_group',
        'disability_subgroup',
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
        'last_medical_check_at',
        'next_medical_check_at',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'last_medical_check_at' => 'date',
        'next_medical_check_at' => 'date',
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

    /**
     * Nationalkader-Zugehörigkeiten (neueste zuerst).
     */
    public function kaderMemberships(): HasMany
    {
        return $this->hasMany(AthleteKaderMembership::class)->orderByDesc('valid_from');
    }

    /** Ist der Athlet an einem bestimmten Stichtag Mitglied eines Nationalkaders? */
    public function isInKaderOn(Carbon|string $date): bool
    {
        return $this->kaderMemberships()->activeOn($date)->exists();
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

    /** Behinderungsgruppe samt PI-Untergruppe, z. B. "PI – Körperliche Beeinträchtigung (Rollstuhl)". */
    public function getDisabilityGroupLabelAttribute(): ?string
    {
        if ($this->disability_group === null) {
            return null;
        }

        $label = $this->disability_group.' – '.(self::DISABILITY_GROUPS[$this->disability_group] ?? $this->disability_group);
        $subgroup = self::DISABILITY_SUBGROUPS[$this->disability_subgroup ?? ''] ?? null;

        return $subgroup === null ? $label : $label.' ('.$subgroup.')';
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

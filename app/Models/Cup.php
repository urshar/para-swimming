<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cup extends Model
{
    protected $fillable = [
        'year',
        'name',
        'base_time_version_id',
        'rounds_count',
        'best_of_count',
        'top_group_points_threshold',
        'is_active',
    ];

    protected $casts = [
        'year' => 'integer',
        'rounds_count' => 'integer',
        'best_of_count' => 'integer',
        'top_group_points_threshold' => 'integer',
        'is_active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function baseTimeVersion(): BelongsTo
    {
        return $this->belongsTo(BaseTimeVersion::class);
    }

    public function meets(): HasMany
    {
        return $this->hasMany(Meet::class);
    }

    public function groupSettings(): HasMany
    {
        return $this->hasMany(CupGroupSetting::class);
    }

    public function ageGroupSettings(): HasMany
    {
        return $this->hasMany(CupAgeGroupSetting::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Ist eine Sportklassengruppe für dieses Cup-Jahr aktiv?
     * Fehlt ein expliziter Eintrag, gilt die Gruppe als aktiv (Default).
     */
    public function isGroupActive(SportClassGroup $group): bool
    {
        $setting = $this->groupSettings->firstWhere('sport_class_group_id', $group->id);

        return $setting?->is_active ?? true;
    }

    /**
     * Werden Damen und Herren in dieser Sportklassengruppe gemeinsam gewertet
     * (eine Rangliste statt zweier getrennter)? Default: getrennt (false).
     */
    public function isGenderCombined(SportClassGroup $group): bool
    {
        $setting = $this->groupSettings->firstWhere('sport_class_group_id', $group->id);

        return $setting?->gender_combined ?? false;
    }

    /**
     * Ist eine Altersgruppe für dieses Cup-Jahr aktiv (Punkt 5 der Spec,
     * konkretisiert durch Erik — generisch über alle Altersgruppen, nicht
     * nur "Jugend")? Fehlt ein expliziter Eintrag, gilt sie als aktiv (Default).
     */
    public function isAgeGroupActive(AgeGroup $ageGroup): bool
    {
        $setting = $this->ageGroupSettings->firstWhere('age_group_id', $ageGroup->id);

        return $setting?->is_active ?? true;
    }
}

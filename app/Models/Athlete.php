<?php

namespace App\Models;

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
        'lenex_athlete_id',
    ];

    protected $casts = [
        'birth_date' => 'date',
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

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->name_prefix,
            $this->first_name,
            $this->last_name,
        ]);

        return implode(' ', $parts);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->last_name.', '.$this->first_name;
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
}

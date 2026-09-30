<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Nation extends Model
{
    protected $fillable = [
        'code',
        'name_de',
        'name_en',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function clubs(): HasMany
    {
        return $this->hasMany(Club::class);
    }

    public function athletes(): HasMany
    {
        return $this->hasMany(Athlete::class);
    }

    public function meets(): HasMany
    {
        return $this->hasMany(Meet::class);
    }

    public function swimRecords(): HasMany
    {
        return $this->hasMany(SwimRecord::class);
    }

    /** Rekorde, deren Veranstaltung in dieser Nation stattfand (swim_records.meet_nation_id). */
    public function meetSwimRecords(): HasMany
    {
        return $this->hasMany(SwimRecord::class, 'meet_nation_id');
    }

    public function classifiers(): HasMany
    {
        return $this->hasMany(Classifier::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        return $this->code.' – '.$this->name_de;
    }

    /**
     * Anzahl der Datensätze, die auf diese Nation verweisen — je Relation, nur Einträge mit Anzahl > 0. Soft-gelöschte Athleten/Vereine/Veranstaltungen/Klassifizierer zählen mit: Ihre Zeilen
     * existieren weiter, bei Athleten/Vereinen/Veranstaltungen blockiert der FK (restrictOnDelete) sonst
     * mit einem DB-Fehler, bei Klassifizierern ginge die Zuordnung beim Wiederherstellen verloren.
     *
     * @return array<string, int>
     */
    public function referenceCounts(): array
    {
        $counts = [
            'athletes' => $this->athletes()->withTrashed()->count(),
            'clubs' => $this->clubs()->withTrashed()->count(),
            'meets' => $this->meets()->withTrashed()->count(),
            'swimRecords' => $this->swimRecords()->count(),
            'meetSwimRecords' => $this->meetSwimRecords()->count(),
            'classifiers' => $this->classifiers()->withTrashed()->count(),
        ];

        return array_filter($counts, static fn (int $count) => $count > 0);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

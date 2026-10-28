<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrokeType extends Model
{
    /**
     * Abweichende Schreibweisen in LENEX-Dateien fremder Erzeuger → LENEX-Code (z. B. "BUTTERFLY" statt "FLY" in
     * automatisch konvertierten Ergebnisdateien).
     */
    private const LENEX_CODE_ALIASES = [
        'BUTTERFLY' => 'FLY',
    ];

    protected $fillable = [
        'code',
        'lenex_code',
        'name_de',
        'name_en',
        'category',
        'is_relay_stroke',
        'is_active',
    ];

    protected $casts = [
        'is_relay_stroke' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function swimEvents(): HasMany
    {
        return $this->hasMany(SwimEvent::class);
    }

    public function swimRecords(): HasMany
    {
        return $this->hasMany(SwimRecord::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeStandard($query)
    {
        return $query->where('category', 'standard');
    }

    public function scopeFin($query)
    {
        return $query->where('category', 'fin');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public static function findByLenexCode(string $lenexCode): ?self
    {
        return self::where('lenex_code', self::LENEX_CODE_ALIASES[$lenexCode] ?? $lenexCode)->first();
    }
}

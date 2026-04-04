<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Club extends Model
{
    use SoftDeletes;

    // ── regionale Verbandscodes (Österreich) ──────────────────────────────────

    const array REGIONAL_ASSOCIATIONS = [
        'BBSV' => 'Burgenländischer Behindertensportverband',
        'KLSV' => 'Kärntner Behindertensportverband',
        'NOEVSV' => 'Niederösterreichischer Versehrtensportverband',
        'OBSV' => 'Oberösterreichsicher Behindertensportverband',
        'SBSV' => 'Salzburger Behindertensportverband',
        'STBSV' => 'Steirischer Behindertensportverband',
        'TBSV' => 'Tiroler Behindertensportverband',
        'VBSV' => 'Vorarlberger Behindertensportverband',
        'WBSV' => 'Wiener Behindertensportverband',
    ];

    protected $fillable = [
        'name',
        'short_name',
        'code',
        'nation_id',
        'type',
        'regional_association',
        'swrid',
        'lenex_club_id',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function athletes(): HasMany
    {
        return $this->hasMany(Athlete::class);
    }

    public function meets(): BelongsToMany
    {
        return $this->belongsToMany(Meet::class, 'meet_club');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        return $this->short_name ?? $this->name;
    }

    /**
     * Gibt den vollen Namen des Regionalverbands zurück.
     * z.B. "WBSV" → "Wiener BehindertenSportVerband"
     */
    public function getRegionalAssociationNameAttribute(): ?string
    {
        if (! $this->regional_association) {
            return null;
        }

        return self::REGIONAL_ASSOCIATIONS[$this->regional_association] ?? $this->regional_association;
    }

    /**
     * Gibt den record_type-Wert für Regionalrekorde zurück.
     * z.B. "AUT.WBSV"
     * Null, wenn kein Regionalverband zugeordnet.
     */
    public function getRegionalRecordTypeAttribute(): ?string
    {
        if (! $this->regional_association) {
            return null;
        }

        return 'AUT.'.$this->regional_association;
    }

    /**
     * Ist dieser Club einem österreichischen Regionalverband zugeordnet?
     */
    public function hasRegionalAssociation(): bool
    {
        return ! empty($this->regional_association);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Club extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    // ── regionale Verbandscodes (Österreich) ──────────────────────────────────

    /** Verband statt Verein (Landesverband, ÖBSV); Rekorde dafür zählen nicht für den Stammverein. */
    public const string TYPE_VERBAND = 'VERBAND';

    const array REGIONAL_ASSOCIATIONS = [
        'BBSV' => 'Burgenländischer Behindertensportverband',
        'KBSV' => 'Kärntner Behindertensportverband',
        'NOEVSV' => 'Niederösterreichischer Versehrtensportverband',
        'OOEBSV' => 'Oberösterreichsicher Behindertensportverband',
        'SBSV' => 'Salzburger Behindertensportverband',
        'STBSV' => 'Steirischer Behindertensportverband',
        'TBSV' => 'Tiroler Behindertensportverband',
        'VBSV' => 'Vorarlberger Behindertensportverband',
        'WBSV' => 'Wiener Behindertensportverband',
    ];

    /**
     * Bundesland je Verbandscode — kurze Anzeigeform für Dropdowns (z.B. "BBSV Burgenland"
     * statt des vollen Vereinsnamens "Burgenländischer Behindertensportverband", der in einem
     * schmalen Select abgeschnitten wird). Siehe records/index.blade.php.
     */
    const array REGIONAL_ASSOCIATION_STATES = [
        'BBSV' => 'Burgenland',
        'KBSV' => 'Kärnten',
        'NOEVSV' => 'Niederösterreich',
        'OOEBSV' => 'Oberösterreich',
        'SBSV' => 'Salzburg',
        'STBSV' => 'Steiermark',
        'TBSV' => 'Tirol',
        'VBSV' => 'Vorarlberg',
        'WBSV' => 'Wien',
    ];

    protected $fillable = [
        'name',
        'short_name',
        'code',
        'nation_id',
        'type',
        'regional_association',
        'swrid',
        'contact_name',
        'contact_email',
        'address_street',
        'address_zip',
        'address_city',
        'address_country',
    ];

    // ── Vereinscode (eindeutig je Nation, Spec club-permissions N8, P7-1/P7-2) ──

    /** Getrimmt und in Großbuchstaben, leer → null; so vergleichen MySQL und SQLite gleich. */
    public static function normalizeCode(?string $code): ?string
    {
        $code = mb_strtoupper(trim((string) $code));

        return $code === '' ? null : $code;
    }

    /** Verein (auch gelöscht), der den Code in der Nation schon trägt. */
    public static function holderOfCode(?string $code, int $nationId, ?int $ignoreId): ?self
    {
        $code = self::normalizeCode($code);

        return $code === null ? null : self::withTrashed()
            ->where('nation_id', $nationId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();
    }

    /**
     * Satz für die Import-Rückmeldung: Vereine, die ohne Code angelegt wurden, weil der Code in der Nation schon
     * vergeben war (P7-3). Leer, wenn es keine gibt.
     *
     * @param  list<string>  $names
     */
    public static function withoutCodeNote(array $names): string
    {
        return $names === [] ? '' : ' Ohne Code angelegt, weil der Code in der Nation schon vergeben ist: '
            .implode(', ', $names).'.';
    }

    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = self::normalizeCode($value);
    }

    // ── Änderungsprotokoll ────────────────────────────────────────────────────

    /** Der Verein selbst ist der Bezug für seinen Verlauf (Trait Auditable). */
    public function auditClubId(): ?int
    {
        return $this->id;
    }

    // ── Relationen ────────────────────────────────────────────────────────────

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function athletes(): HasMany
    {
        return $this->hasMany(Athlete::class);
    }

    public function coaches(): HasMany
    {
        return $this->hasMany(Coach::class);
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

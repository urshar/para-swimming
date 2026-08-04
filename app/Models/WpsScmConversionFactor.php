<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Umrechnungsfaktor Kurzbahn → Langbahn.
 *
 *     p_LCM = p_SCM × factor
 *
 * Die Auflösung erfolgt über eine Kaskade vom Spezifischen zum Allgemeinen (Spec §9.3):
 * Stil+Strecke+Klasse → Stil+Strecke → Stil. Damit erhalten gut besetzte Kombinationen einen
 * klassenspezifisch geeichten Faktor, während dünn besetzte auf den Sammelwert zurückfallen.
 *
 * Das ist fachlich geboten: Der Wendenvorteil ist genau das, was sich zwischen den
 * Sportklassen am stärksten unterscheidet. Ein S3-Athlet zieht aus einer zusätzlichen Wende
 * deutlich weniger Nutzen als ein S14-Athlet.
 */
class WpsScmConversionFactor extends Model
{
    /** Aus eigenen Ergebnissen ermittelt (Median der Einzelverhältnisse). */
    public const string SOURCE_OWN_DATA = 'own_data';

    /** Startwert aus dem allgemeinen Schwimmsport — nicht para-spezifisch erhoben. */
    public const string SOURCE_LITERATURE = 'literature';

    /** Von einem Administrator gesetzt. */
    public const string SOURCE_MANUAL = 'manual';

    /** @used-by WpsPointsPhase5Test zur Validierung erlaubter source-Werte */
    public const array SOURCES = [
        self::SOURCE_OWN_DATA,
        self::SOURCE_LITERATURE,
        self::SOURCE_MANUAL,
    ];

    public const string CONFIDENCE_HIGH = 'high';

    public const string CONFIDENCE_MEDIUM = 'medium';

    public const string CONFIDENCE_LOW = 'low';

    /** @used-by WpsPointsPhase5Test zur Validierung erlaubter confidence_level-Werte */
    public const array CONFIDENCE_LEVELS = [
        self::CONFIDENCE_HIGH,
        self::CONFIDENCE_MEDIUM,
        self::CONFIDENCE_LOW,
    ];

    protected $fillable = [
        'stroke_type_id',
        'distance',
        'sport_class',
        'gender',
        'factor',
        'source',
        'sample_size',
        'confidence_level',
        'notes',
        'approved_by',
        'approved_at',
        'active',
    ];

    protected $casts = [
        'distance' => 'integer',
        'factor' => 'float',
        'sample_size' => 'integer',
        'approved_at' => 'datetime',
        'active' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function strokeType(): BelongsTo
    {
        return $this->belongsTo(StrokeType::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Spezifitätsgrad für die Auflösungskaskade — je höher, desto passgenauer.
     *
     * Die Gewichte sind so gewählt, dass die Sportklasse schwerer wiegt als die Strecke:
     * Der Wendenvorteil unterscheidet sich zwischen den Klassen stärker als zwischen den
     * Strecken eines Stils.
     */
    public function specificity(): int
    {
        return ($this->sport_class !== null ? 4 : 0)
            + ($this->distance !== null ? 2 : 0)
            + ($this->gender !== null ? 1 : 0);
    }

    public function isFromOwnData(): bool
    {
        return $this->source === self::SOURCE_OWN_DATA;
    }

    /** Kurzbeschreibung für Anzeige und Berichte, z.B. "1,0266 (eigene Daten, 7 Athleten)". */
    public function getDescriptionAttribute(): string
    {
        $herkunft = match ($this->source) {
            self::SOURCE_OWN_DATA => 'eigene Daten',
            self::SOURCE_LITERATURE => 'Literaturwert',
            default => 'manuell gesetzt',
        };

        if ($this->sample_size !== null) {
            $herkunft .= ", $this->sample_size Athlet(en)";
        }

        return number_format($this->factor, 4, ',', '.')." ($herkunft)";
    }
}

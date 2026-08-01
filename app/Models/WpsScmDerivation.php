<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadaten zu einer SCM-Ableitung.
 *
 * World Para Swimming veröffentlicht keine offiziellen SCM-Parameter. Die abgeleiteten Werte
 * selbst liegen in wps_point_parameters mit official = false; dieses Model dokumentiert
 * ausschließlich, wie sie zustande gekommen sind und wer sie freigegeben hat.
 */
class WpsScmDerivation extends Model
{
    public const string METHOD_PERFORMANCE_RATIO = 'performance_ratio';

    public const string METHOD_DISTANCE_ADJUSTMENT = 'distance_adjustment';

    public const string METHOD_FEDERATION_DATA = 'federation_data';

    /** @used-by WpsPointsPhase1Test zur Validierung erlaubter conversion_method-Werte */
    public const array METHODS = [
        self::METHOD_PERFORMANCE_RATIO,
        self::METHOD_DISTANCE_ADJUSTMENT,
        self::METHOD_FEDERATION_DATA,
    ];

    public const string CONFIDENCE_HIGH = 'high';

    public const string CONFIDENCE_MEDIUM = 'medium';

    public const string CONFIDENCE_LOW = 'low';

    /** @used-by WpsPointsPhase1Test zur Validierung erlaubter confidence_level-Werte */
    public const array CONFIDENCE_LEVELS = [
        self::CONFIDENCE_HIGH,
        self::CONFIDENCE_MEDIUM,
        self::CONFIDENCE_LOW,
    ];

    protected $fillable = [
        'wps_point_version_id',
        'conversion_method',
        'source',
        'confidence_level',
        'sample_size',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'sample_size' => 'integer',
        'approved_at' => 'datetime',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function version(): BelongsTo
    {
        return $this->belongsTo(WpsPointVersion::class, 'wps_point_version_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }
}

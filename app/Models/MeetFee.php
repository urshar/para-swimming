<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Meldegebühr einer Veranstaltung (session_number leer) oder eines Abschnitts — LENEX FEES > FEE.
 * Die Gebühr je Meldung in einem Bewerb (EVENT > FEE) steht nicht hier, sondern in swim_events.fee_cents.
 *
 * @property int $id
 * @property int $meet_id
 * @property int|null $session_number
 * @property string $type Eine der TYPE_*-Konstanten (LENEX FEE@type)
 * @property int $amount_cents
 * @property string $currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Meet|null $meet
 */
class MeetFee extends Model
{
    public const string TYPE_CLUB = 'CLUB';

    public const string TYPE_ATHLETE = 'ATHLETE';

    public const string TYPE_RELAY = 'RELAY';

    public const string TYPE_TEAM = 'TEAM';

    public const string TYPE_LATE_INDIVIDUAL = 'LATEENTRY.INDIVIDUAL';

    public const string TYPE_LATE_RELAY = 'LATEENTRY.RELAY';

    /**
     * Alle LENEX-Typen mit Anzeigename. TEAM wird gespeichert und per LENEX ausgetauscht, fließt aber
     * noch nicht in die Abrechnung ein (siehe docs/open-points.md).
     *
     * @var array<string, string>
     */
    public const array TYPES = [
        self::TYPE_CLUB => 'Je Verein',
        self::TYPE_ATHLETE => 'Je Athlet',
        self::TYPE_RELAY => 'Je Staffel',
        self::TYPE_TEAM => 'Je Mannschaft',
        self::TYPE_LATE_INDIVIDUAL => 'Nachmeldung je Einzelstart',
        self::TYPE_LATE_RELAY => 'Nachmeldung je Staffel',
    ];

    /** Typen, die (noch) nicht berechnet werden. */
    public const array NOT_CALCULATED = [
        self::TYPE_TEAM,
    ];

    protected $fillable = [
        'meet_id',
        'session_number',
        'type',
        'amount_cents',
        'currency',
    ];

    protected $casts = [
        'session_number' => 'integer',
        'amount_cents' => 'integer',
    ];

    public function meet(): BelongsTo
    {
        return $this->belongsTo(Meet::class);
    }
}

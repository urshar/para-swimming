<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Antrag eines Vereins auf Namensänderung oder Ummeldung eines Athleten (Spec club-permissions §4.5). Gestellt,
 * zurückgezogen und entschieden über App\Services\ChangeRequestService.
 *
 * @property int $id
 * @property string $type athlete_name oder athlete_transfer
 * @property int $athlete_id
 * @property int|null $club_id Antragstellender Verein (bei der Ummeldung der aufnehmende)
 * @property int|null $from_club_id Verein des Athleten beim Stellen (Kopie)
 * @property array<string, string|null> $values Namensänderung: first_name, last_name, name_prefix; Ummeldung: joined_at
 * @property string $reason
 * @property string $status open, approved, rejected, withdrawn
 * @property int|null $requested_by
 * @property string|null $requested_by_name
 * @property int|null $decided_by
 * @property string|null $decided_by_name
 * @property Carbon|null $decided_at
 * @property string|null $response Antwort des Admins
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $type_label
 * @property-read string $status_label
 * @property-read string $status_color
 * @property-read Athlete|null $athlete
 * @property-read Club|null $club
 * @property-read Club|null $fromClub
 * @property-read User|null $requester
 */
class ChangeRequest extends Model
{
    use Auditable;

    public const string TYPE_NAME = 'athlete_name';

    public const string TYPE_TRANSFER = 'athlete_transfer';

    public const string STATUS_OPEN = 'open';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_WITHDRAWN = 'withdrawn';

    public const array TYPE_LABELS = [
        self::TYPE_NAME => 'Namensänderung',
        self::TYPE_TRANSFER => 'Ummeldung',
    ];

    public const array STATUS_LABELS = [
        self::STATUS_OPEN => 'offen',
        self::STATUS_APPROVED => 'freigegeben',
        self::STATUS_REJECTED => 'abgelehnt',
        self::STATUS_WITHDRAWN => 'zurückgezogen',
    ];

    private const array STATUS_COLORS = [
        self::STATUS_OPEN => 'amber',
        self::STATUS_APPROVED => 'green',
        self::STATUS_REJECTED => 'red',
        self::STATUS_WITHDRAWN => 'zinc',
    ];

    protected $fillable = [
        'type',
        'athlete_id',
        'club_id',
        'from_club_id',
        'values',
        'reason',
        'status',
        'requested_by',
        'requested_by_name',
        'decided_by',
        'decided_by_name',
        'decided_at',
        'response',
    ];

    protected $casts = [
        'values' => 'array',
        'decided_at' => 'datetime',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class)->withTrashed();
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class)->withTrashed();
    }

    public function fromClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'from_club_id')->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    // ── Scopes und Anzeige ────────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'zinc';
    }

    // ── Änderungsprotokoll ────────────────────────────────────────────────────

    /** Wer und wann steht im Protokolleintrag selbst; die Kopien hier wären doppelt. */
    protected function auditExcluded(): array
    {
        return ['requested_by', 'requested_by_name', 'decided_by', 'decided_by_name', 'decided_at'];
    }
}

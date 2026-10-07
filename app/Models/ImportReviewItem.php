<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eintrag der Prüfliste nach dem LENEX-Rekordimport bzw. der Bestandsprüfung.
 *
 * @property int $id
 * @property string $type
 * @property int $athlete_id
 * @property int|null $current_club_id
 * @property int|null $lenex_club_id
 * @property int|null $swim_record_id
 * @property string|null $source
 * @property array|null $details
 * @property string $status
 */
class ImportReviewItem extends Model
{
    public const string TYPE_CLUB_CONFLICT = 'club_conflict';

    public const string TYPE_YEAR_MATCH = 'year_match';

    /** AUT- oder Regionalrekord eines Athleten, dessen Nationalität nicht AUT ist. */
    public const string TYPE_NATIONALITY = 'nationality';

    public const string STATUS_OPEN = 'open';

    /** Vereinskonflikt: Verein übernommen; Jahres-Treffer: als richtig geprüft; Nationalität: Rekord entfernt. */
    public const string STATUS_APPLIED = 'applied';

    public const string STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'type',
        'athlete_id',
        'current_club_id',
        'lenex_club_id',
        'swim_record_id',
        'source',
        'details',
        'status',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'details' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function currentClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'current_club_id');
    }

    public function lenexClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'lenex_club_id');
    }

    public function swimRecord(): BelongsTo
    {
        return $this->belongsTo(SwimRecord::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

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
        return match ($this->type) {
            self::TYPE_CLUB_CONFLICT => 'Vereinskonflikt',
            self::TYPE_NATIONALITY => 'Nationalität nicht AUT',
            default => 'Geburtsdatum abweichend',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Offen',
            self::STATUS_APPLIED => match ($this->type) {
                self::TYPE_CLUB_CONFLICT => 'Übernommen',
                self::TYPE_NATIONALITY => 'Entfernt',
                default => 'Geprüft',
            },
            default => 'Ignoriert',
        };
    }
}

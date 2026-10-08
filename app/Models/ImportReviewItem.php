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
 * @property int|null $athlete_id
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

    /** Regionalrekord, dessen Verband nicht zum Verein des Rekords passt. */
    public const string TYPE_REGIONAL = 'regional_mismatch';

    /** Rekord aus einer importierten Liste widerspricht der Rekordkette in der DB (die Liste ist maßgeblich). */
    public const string TYPE_LIST_MISMATCH = 'list_mismatch';

    /** Nationaler oder regionaler Staffelrekord ohne Verein (und damit ohne prüfbare Mitglieder). */
    public const string TYPE_RELAY_NO_CLUB = 'relay_no_club';

    public const string STATUS_OPEN = 'open';

    /**
     * Vereinskonflikt: Verein übernommen; Jahres-Treffer: geprüft; Nationalität/Regionalverband: Rekord entfernt;
     * Abweichung zur Liste: Liste übernommen; Staffel ohne Verein: verknüpft bzw. geprüft.
     */
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

    /** Arten, deren Aktion den Rekord entfernt (SwimRecord::removeFromHistory). */
    public function removesRecord(): bool
    {
        return in_array($this->type, [self::TYPE_NATIONALITY, self::TYPE_REGIONAL], true);
    }

    /** Arten, deren Aktion Rekorde löscht — die Schaltfläche fragt vorher nach. */
    public function deletesRecords(): bool
    {
        return $this->removesRecord() || $this->type === self::TYPE_LIST_MISMATCH;
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
            self::TYPE_REGIONAL => 'Regionalrekord: falscher Verband',
            self::TYPE_LIST_MISMATCH => 'Abweichung zur Rekordliste',
            self::TYPE_RELAY_NO_CLUB => 'Staffelrekord ohne Verein',
            default => 'Geburtsdatum abweichend',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Offen',
            self::STATUS_APPLIED => match ($this->type) {
                self::TYPE_CLUB_CONFLICT => 'Übernommen',
                self::TYPE_NATIONALITY, self::TYPE_REGIONAL => 'Entfernt',
                self::TYPE_LIST_MISMATCH => 'Liste übernommen',
                self::TYPE_RELAY_NO_CLUB => isset($this->details['linked_relay_result_id']) ? 'Verknüpft' : 'Geprüft',
                default => 'Geprüft',
            },
            default => 'Ignoriert',
        };
    }
}

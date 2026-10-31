<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Eintrag im Änderungsprotokoll (Spec club-permissions §4.7). Geschrieben ausschließlich über App\Services\AuditLogger.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $user_name Kopie des Namens, bleibt nach dem Löschen des Benutzers sichtbar
 * @property string $auditable_type
 * @property int $auditable_id
 * @property string $event created, updated, deleted, restored, merged, transferred
 * @property array<string, array{0: mixed, 1: mixed}> $changes Feld → [alt, neu]
 * @property string $source web, import, console
 * @property string|null $source_detail z. B. Dateiname beim Import
 * @property int|null $athlete_id
 * @property int|null $club_id
 * @property Carbon|null $created_at
 * @property-read User|null $user
 * @property-read Model|null $auditable
 */
class AuditLog extends Model
{
    public const ?string UPDATED_AT = null;

    public const string SOURCE_WEB = 'web';

    public const string SOURCE_IMPORT = 'import';

    public const string SOURCE_CONSOLE = 'console';

    protected $fillable = [
        'user_id',
        'user_name',
        'auditable_type',
        'auditable_id',
        'event',
        'changes',
        'source',
        'source_detail',
        'athlete_id',
        'club_id',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Verlauf eines Athleten: der Athlet selbst, seine Einzelmeldungen (P4-2) und Anträge zu ihm (Phase 6). */
    public function scopeForAthlete(Builder $query, Athlete $athlete): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->where('auditable_type', Athlete::class)->where('auditable_id', $athlete->id))
            ->orWhere(fn (Builder $w) => $w->whereIn('auditable_type', [Entry::class, ChangeRequest::class])
                ->where('athlete_id', $athlete->id)));
    }

    /** Verlauf eines Vereins: der Verein selbst, seine Staffelmeldungen (P4-2) und seine Trainer (Phase 8). */
    public function scopeForClub(Builder $query, Club $club): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->where('auditable_type', Club::class)->where('auditable_id', $club->id))
            ->orWhere(fn (Builder $w) => $w->whereIn('auditable_type', [RelayEntry::class, Coach::class, CoachQualification::class])
                ->where('club_id', $club->id)));
    }
}

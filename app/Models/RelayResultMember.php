<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesMeetRecordCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RelayResultMember — eingesetzter Schwimmer einer Staffel (LENEX RELAYPOSITION).
 *
 * athlete_id ist optional (im Import nicht zuordenbarer Athlet); Name und Geschlecht werden deshalb als Kopie
 * mitgespeichert.
 *
 * @property int $id
 * @property int $relay_result_id
 * @property int $position
 * @property int|null $athlete_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $gender
 * @property string|null $sport_class
 * @property int|null $reaction_time
 */
class RelayResultMember extends Model
{
    use InvalidatesMeetRecordCheck;

    protected $fillable = [
        'relay_result_id',
        'position',
        'athlete_id',
        'first_name',
        'last_name',
        'gender',
        'sport_class',
        'reaction_time',
    ];

    public function relayResult(): BelongsTo
    {
        return $this->belongsTo(RelayResult::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    /** Geschlecht des Athleten, ersatzweise die gespeicherte Kopie. */
    public function memberGender(): ?string
    {
        return $this->athlete?->gender ?? $this->gender;
    }

    /** "Nachname, Vorname" des Athleten, ersatzweise aus der gespeicherten Kopie. */
    public function getDisplayNameAttribute(): string
    {
        if ($this->athlete) {
            return $this->athlete->display_name;
        }

        return trim(($this->last_name ?? '').', '.($this->first_name ?? ''), ', ');
    }

    // ── Prüfstatus Rekordcheck ────────────────────────────────────────────────

    protected function recordRelevantAttributes(): array
    {
        return ['relay_result_id', 'position', 'athlete_id', 'gender', 'sport_class'];
    }

    protected function recordCheckMeetIds(): array
    {
        return RelayResult::query()
            ->whereKey(array_filter([$this->relay_result_id, $this->getOriginal('relay_result_id')]))
            ->distinct()->pluck('meet_id')->map(fn ($id) => (int) $id)->values()->all();
    }
}

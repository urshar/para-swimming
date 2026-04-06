<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RelayTeamMember
 *
 * Staffelmitglied das einen Rekord aufgestellt hat.
 * LENEX 3.0: RECORD > RELAY > RELAYPOSITIONS > RELAYPOSITION > ATHLETE
 *
 * @property int $id
 * @property int $swim_record_id
 * @property int $position
 * @property string $first_name
 * @property string $last_name
 * @property string|null $birth_date
 * @property string|null $gender
 * @property int|null $athlete_id
 */
class RelayTeamMember extends Model
{
    protected $fillable = [
        'swim_record_id',
        'position',
        'first_name',
        'last_name',
        'birth_date',
        'gender',
        'athlete_id',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    public function swimRecord(): BelongsTo
    {
        return $this->belongsTo(SwimRecord::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}

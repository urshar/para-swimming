<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dublettenvorschlag, den ein Admin als "kein Duplikat" markiert hat. athlete_id ist immer die kleinere der beiden IDs.
 *
 * @property int $id
 * @property int $athlete_id
 * @property int $other_athlete_id
 * @property int|null $dismissed_by
 */
class AthleteDuplicateDismissal extends Model
{
    protected $fillable = [
        'athlete_id',
        'other_athlete_id',
        'dismissed_by',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function otherAthlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class, 'other_athlete_id');
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}

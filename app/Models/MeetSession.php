<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Abschnitt (Session) einer Veranstaltung mit Datum und Startzeit — LENEX SESSION@date/@daytime.
 * Die Disziplinen hängen über swim_events.session_number = number am Abschnitt (kein Fremdschlüssel).
 *
 * @property int $id
 * @property int $meet_id
 * @property int $number
 * @property Carbon|null $date
 * @property string|null $daytime "HH:MM:SS" (Spaltentyp time)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $daytime_short
 * @property-read Meet|null $meet
 */
class MeetSession extends Model
{
    protected $fillable = [
        'meet_id',
        'number',
        'date',
        'daytime',
    ];

    protected $casts = [
        'number' => 'integer',
        'date' => 'date',
    ];

    public function meet(): BelongsTo
    {
        return $this->belongsTo(Meet::class);
    }

    /** Startzeit als "HH:MM" (ohne Sekunden), null wenn keine gesetzt. */
    public function getDaytimeShortAttribute(): ?string
    {
        return $this->daytime ? substr($this->daytime, 0, 5) : null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Start eines Vereins außerhalb Österreichs (Gast) — nur für die Statistik und die vollständige Ergebnisliste, ohne
 * Athleten- oder Vereinsdatensatz. Ausländisch entscheidet die Nation des Vereins, nicht die des Schwimmers.
 */
class ForeignStart extends Model
{
    /** Vereinsnation, deren Starts als reguläre Ergebnisse importiert werden. */
    public const string DOMESTIC_NATION = 'AUT';

    protected $fillable = [
        'meet_id',
        'swim_event_id',
        'is_relay',
        'club_name',
        'club_code',
        'nation_id',
        'person_key',
        'last_name',
        'first_name',
        'birth_date',
        'gender',
        'sport_class',
        'swim_time',
        'status',
        'place',
        'lenex_result_id',
    ];

    protected $casts = [
        'is_relay' => 'boolean',
        'birth_date' => 'date',
        'swim_time' => 'integer',
        'place' => 'integer',
    ];

    public function meet(): BelongsTo
    {
        return $this->belongsTo(Meet::class);
    }

    public function swimEvent(): BelongsTo
    {
        return $this->belongsTo(SwimEvent::class);
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /** Ist ein Verein dieser Nation (LENEX CLUB nation) ein Gast? Ohne Angabe gilt er als österreichisch. */
    public static function isForeignClubNation(string $nationCode): bool
    {
        $code = strtoupper(trim($nationCode));

        return $code !== '' && $code !== self::DOMESTIC_NATION;
    }

    /** Teilnehmerschlüssel: Nachname, Vorname, Geburtsdatum — klein geschrieben. */
    public static function personKey(string $lastName, string $firstName, ?string $birthDate): string
    {
        return mb_strtolower(trim($lastName).'|'.trim($firstName).'|'.($birthDate ?? ''));
    }
}

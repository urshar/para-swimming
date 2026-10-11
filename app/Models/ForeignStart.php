<?php

namespace App\Models;

use App\Support\TimeParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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

    /**
     * Reihenfolge im Block "Gäste" der Ergebnislisten (ohne Platz): Starts mit Zeit nach der Zeit, danach DSQ, DNF,
     * DNS, SICK, WDR, jeweils nach Name.
     *
     * @param  Collection<int, ForeignStart>  $starts
     * @return Collection<int, ForeignStart>
     */
    public static function inListOrder(Collection $starts): Collection
    {
        $statusOrder = ['DSQ' => 1, 'DNF' => 2, 'DNS' => 3, 'SICK' => 4, 'WDR' => 5];

        return $starts->sortBy(fn (ForeignStart $start): string => sprintf(
            '%d-%010d-%s',
            $start->swim_time !== null && ! isset($statusOrder[$start->status ?? '']) ? 0 : ($statusOrder[$start->status ?? ''] ?? 6),
            $start->swim_time ?? 0,
            mb_strtolower($start->last_name.' '.$start->first_name.' '.$start->club_name),
        ))->values();
    }

    /** Verein mit Nation, z. B. "Swiss Paralympic Club (SUI)". */
    public function getClubLabelAttribute(): string
    {
        return $this->nation?->code === null ? $this->club_name : $this->club_name.' ('.$this->nation->code.')';
    }

    /** "Nachname Vorname" wie Athlete::full_name; bei Staffeln der Vereinsname. */
    public function getDisplayNameAttribute(): string
    {
        $name = trim($this->last_name.' '.$this->first_name);

        return $name === '' ? $this->club_name : $name;
    }

    /** Anzeigeformat der Zeit wie Result::formatted_swim_time (TimeParser::display()), ohne Zeit null. */
    public function getFormattedSwimTimeAttribute(): ?string
    {
        return $this->swim_time === null ? null : TimeParser::display($this->swim_time);
    }

    /** Teilnehmerschlüssel: Nachname, Vorname, Geburtsdatum — kleingeschrieben. */
    public static function personKey(string $lastName, string $firstName, ?string $birthDate): string
    {
        return mb_strtolower(trim($lastName).'|'.trim($firstName).'|'.($birthDate ?? ''));
    }
}

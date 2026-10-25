<?php

namespace App\Models;

use App\Services\RecordChainService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property bool $from_split Rekord aus einer Zwischenzeit (docs/specs/split-records.md)
 */
class SwimRecord extends Model
{
    protected $fillable = [
        'stroke_type_id',
        'nation_id',
        'meet_nation_id',
        'athlete_id',
        'result_id',
        'relay_result_id',
        'superseded_by_id',
        'supersedes_id',
        'record_type',
        'sport_class',
        'gender',
        'course',
        'distance',
        'relay_count',
        'from_split',
        'swim_time',
        'record_status',
        'is_current',
        'set_date',
        'meet_name',
        'meet_city',
        'meet_course',
        'comment',
        'club_id',
    ];

    protected $casts = [
        'set_date' => 'date',
        'is_current' => 'boolean',
        'from_split' => 'boolean',
    ];

    // ── Relationen ────────────────────────────────────────────────────────────

    public function strokeType(): BelongsTo
    {
        return $this->belongsTo(StrokeType::class);
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /** Austragungsland des Wettkampfs (LENEX MEETINFO@nation) — nicht zu verwechseln mit nation(). */
    public function meetNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'meet_nation_id');
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    /** Herkunft eines Staffelrekords (Gegenstück zu result()). */
    public function relayResult(): BelongsTo
    {
        return $this->belongsTo(RelayResult::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    /** Der neuere Rekord der diesen ersetzt hat */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(SwimRecord::class, 'superseded_by_id');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(RecordSplit::class)->orderBy('distance');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeHistory($query)
    {
        return $query->where('is_current', false);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('record_type', $type);
    }

    /** Wertung als Text: Herren, Damen oder Mixed (nur Staffeln). */
    public static function genderLabel(?string $gender): string
    {
        return match ($gender) {
            'F' => 'Damen',
            'X' => 'Mixed',
            default => 'Herren',
        };
    }

    /** Badge-Farbe der Wertung (Herren blau, Damen rosa, Mixed violett). */
    public static function genderColor(?string $gender): string
    {
        return match ($gender) {
            'F' => 'pink',
            'X' => 'violet',
            default => 'blue',
        };
    }

    public function getFormattedSwimTimeAttribute(): string
    {
        return Entry::formatTime($this->swim_time);
    }

    /** Disziplin als Text: "50 m Freistil", bei Staffeln "4×50 m Freistil". */
    public function getDisciplineLabelAttribute(): string
    {
        $relay = $this->relay_count > 1 ? $this->relay_count.'×' : '';

        return trim($relay.$this->distance.' m '.($this->strokeType?->name_de ?? ''));
    }

    /** Symbol der Wertung: ♀ Damen, ♂ Herren, ⚥ Mixed. */
    public static function genderSymbol(?string $gender): string
    {
        return match ($gender) {
            'F' => '♀',
            'X' => '⚥',
            default => '♂',
        };
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Gibt die vollständige Rekord-Kette zurück (ältester zuerst).
     * Traversiert über supersedes_id rückwärts bis zum ersten Rekord.
     */
    public function getHistoryChain(): Collection
    {
        $chain = collect([$this]);
        $current = $this;

        while ($current->supersedes_id) {
            $current = $current->supersedes()->first();
            if (! $current) {
                break;
            }
            $chain->prepend($current);
        }

        return $chain;
    }

    /** Der ältere Rekord den dieser ersetzt hat */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(SwimRecord::class, 'supersedes_id');
    }

    /**
     * Löscht einen ungültigen Rekord und hält die Historie konsistent: Hatte er einen Nachfolger, wird dieser direkt
     * mit dem Vorgänger verknüpft; war er aktuell, wird der Vorgänger wieder aktuell. Das passende Rekord-Flag am
     * Einzel- bzw. Staffelergebnis wird zurückgesetzt. Genutzt von der Rekordimport-Prüfliste.
     *
     * @throws Throwable
     */
    public function removeFromHistory(): void
    {
        DB::transaction(function () {
            $predecessor = $this->supersedes;
            $successor = SwimRecord::where('supersedes_id', $this->id)->first();

            if ($successor !== null) {
                $successor->update(['supersedes_id' => $predecessor?->id]);
                $predecessor?->update(['superseded_by_id' => $successor->id]);
            } elseif ($this->is_current && $predecessor !== null) {
                $predecessor->update([
                    'is_current' => true,
                    'superseded_by_id' => null,
                    'record_status' => match ($predecessor->record_status) {
                        'APPROVED.HISTORY' => 'APPROVED',
                        'PENDING.HISTORY' => 'PENDING',
                        default => $predecessor->record_status,
                    },
                ]);
            }

            // Rekord-Flag am Einzel- bzw. Staffelergebnis zurücksetzen, wenn kein anderer Rekord es trägt.
            $flag = $this->resultFlag();
            foreach (['result_id' => Result::class, 'relay_result_id' => RelayResult::class] as $column => $model) {
                if ($this->{$column} === null || $flag === null) {
                    continue;
                }
                $stillFlagged = SwimRecord::where($column, $this->{$column})
                    ->whereKeyNot($this->id)
                    ->get(['record_type'])
                    ->contains(fn (SwimRecord $other) => $other->resultFlag() === $flag);
                if (! $stillFlagged) {
                    $model::whereKey($this->{$column})->update([$flag => false]);
                }
            }

            $this->delete();
        });
    }

    /** Rekord-Flag am Ergebnis, das zu diesem Rekordtyp gehört (siehe RecordCheckerService::updateResultFlags). */
    public function resultFlag(): ?string
    {
        return match (true) {
            $this->record_type === 'AUT' => 'is_national_record',
            $this->record_type === 'AUT.JR' => 'is_junior_record',
            (bool) preg_match('/^AUT\.[A-Z]+\.JR$/', $this->record_type) => 'is_regional_junior_record',
            (bool) preg_match('/^AUT\.[A-Z]+$/', $this->record_type) => 'is_regional_record',
            default => null,
        };
    }

    /**
     * Wird aufgerufen wenn dieser Rekord von einem neueren überboten wird.
     * Setzt is_current = false, record_status und superseded_by_id.
     */
    public function markAsSupersededBy(SwimRecord $newRecord): void
    {
        $this->update([
            'is_current' => false,
            'superseded_by_id' => $newRecord->id,
            'record_status' => match ($this->record_status) {
                'APPROVED' => 'APPROVED.HISTORY',
                'PENDING' => 'PENDING.HISTORY',
                default => $this->record_status,
            },
        ]);
    }

    /**
     * Bestätigt einen bisher ausstehenden Rekord (z. B. aus einem Start außer Konkurrenz) und ordnet ihn an seinem
     * Datum in die Kette ein (RecordChainService::insert()): Er löst den damals geltenden Rekord ab; spätere, gleich
     * schnelle oder langsamere Rekorde werden ungültig. War damals schon ein schnellerer Rekord anerkannt oder kommt
     * später einer, geht der bestätigte in die Historie. Setzt das passende Rekord-Flag am Ergebnis.
     *
     * Aufzurufen, nachdem record_status von PENDING auf APPROVED gewechselt hat.
     *
     * @return list<SwimRecord> die dabei auf INVALID gesetzten späteren Rekorde
     *
     * @throws Throwable
     */
    public function approve(): array
    {
        return app(RecordChainService::class)->insert($this);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function relayTeam(): HasMany
    {
        return $this->hasMany(RelayTeamMember::class)->orderBy('position');
    }

    /**
     * Gibt, an ob es sich um einen Staffelrekord handelt.
     */
    public function getIsRelayAttribute(): bool
    {
        return $this->relay_count > 1;
    }

    /**
     * Verein-Anzeigename (Kurzname, falls vorhanden — Club::display_name, wie schon bei den
     * Ergebnissen in Phase 4) zum Zeitpunkt des Rekords. Fallback: aktueller Verein des Athleten.
     */
    public function getRecordClubNameAttribute(): ?string
    {
        return $this->club?->display_name
            ?? $this->athlete?->club?->display_name;
    }
}

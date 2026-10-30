<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\AuditLog;
use App\Models\Entry;
use App\Models\RelayEntry;
use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Schreibt das Änderungsprotokoll (Spec club-permissions §4.7). Model-Events kommen über den Trait
 * App\Models\Concerns\Auditable; die übrigen Methoden decken Änderungen ab, die keine Model-Events auslösen oder als
 * ein zusammenfassender Eintrag lesbarer sind (Staffelmitglieder, Zusammenführen, Vereinswechsel).
 */
final readonly class AuditLogger
{
    public function __construct(private AuditContext $context) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  Feld → [alt, neu]
     */
    public function record(Model $subject, string $event, array $changes): void
    {
        $user = auth()->user();

        AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'event' => $event,
            'changes' => $changes,
            'source' => $this->context->source(),
            'source_detail' => $this->context->detail(),
            'athlete_id' => method_exists($subject, 'auditAthleteId') ? $subject->auditAthleteId() : null,
            'club_id' => method_exists($subject, 'auditClubId') ? $subject->auditClubId() : null,
        ]);
    }

    /**
     * Model-Event aus dem Trait; nichts zu tun, wenn gerade stummgeschaltet oder bei "updated" nichts Protokolliertes
     * geändert ist (z. B. nur updated_at).
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    public function recordModelEvent(Model $subject, string $event, array $changes): void
    {
        if ($this->context->isMuted() || ($event === 'updated' && $changes === [])) {
            return;
        }

        $this->record($subject, $event, $changes);
    }

    /** Mitglieder einer Staffelmeldung in Reihenfolge, als lesbare Liste für den Vorher-Nachher-Vergleich. */
    public function relayMemberNames(RelayEntry $relayEntry): string
    {
        return $relayEntry->members()->orderBy('position')->with('athlete')->get()
            ->map(fn ($member) => $member->athlete !== null
                ? $member->athlete->last_name.' '.$member->athlete->first_name
                : '#'.$member->athlete_id)
            ->implode(', ');
    }

    /** Ein Eintrag "Mitglieder alt → neu", wenn sich die Besetzung gegenüber $before geändert hat. */
    public function relayMembersChanged(RelayEntry $relayEntry, string $before): void
    {
        $after = $this->relayMemberNames($relayEntry);

        if ($after !== $before) {
            $this->record($relayEntry, 'updated', ['members' => [$before === '' ? null : $before, $after === '' ? null : $after]]);
        }
    }

    /**
     * Zusammenführen: Eintrag am behaltenen Athleten; das Löschen des zweiten erfasst der Trait. Die Meldungen des
     * zweiten Athleten gehören jetzt dem behaltenen, deshalb wandert auch ihr Verlauf mit (nur die Filterspalte).
     */
    public function merged(Athlete $keep, Athlete $drop): void
    {
        $this->record($keep, 'merged', [
            'merged_athlete' => [null, $drop->last_name.' '.$drop->first_name.' (#'.$drop->id.')'],
        ]);

        AuditLog::where('auditable_type', Entry::class)->where('athlete_id', $drop->id)
            ->update(['athlete_id' => $keep->id]);
    }

    /** Vereinswechsel als ein Eintrag (der Aufrufer schaltet das Update von club_id stumm). */
    public function transferred(Athlete $athlete, ?int $fromClubId, int $toClubId, string $joinedAt): void
    {
        $this->record($athlete, 'transferred', [
            'club_id' => [$fromClubId, $toClubId],
            'joined_at' => [null, $joinedAt],
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\AthleteClubHistory;
use App\Support\AuditContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Vereinswechsel eines Athleten: schließt den aktiven Eintrag der Vereins-History, legt einen neuen an und setzt das
 * Convenience-Feld Athlete::club_id. Genutzt von der Athletenseite und der Rekordimport-Prüfliste.
 */
final readonly class AthleteClubTransferService
{
    /**
     * @throws Throwable
     */
    public function transfer(Athlete $athlete, int $clubId, string $joinedAt, ?string $notes): void
    {
        DB::transaction(function () use ($athlete, $clubId, $joinedAt, $notes) {
            AthleteClubHistory::where('athlete_id', $athlete->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'left_at' => Carbon::parse($joinedAt)->subDay()->toDateString(),
                ]);

            AthleteClubHistory::create([
                'athlete_id' => $athlete->id,
                'club_id' => $clubId,
                'joined_at' => $joinedAt,
                'is_active' => true,
                'notes' => $notes,
            ]);

            // Ein Eintrag "Vereinswechsel" statt eines einfachen Updates von club_id.
            $audit = app(AuditLogger::class);
            $fromClubId = $athlete->club_id;
            app(AuditContext::class)->muted(fn () => $athlete->update(['club_id' => $clubId]));
            $audit->transferred($athlete, $fromClubId, $clubId, $joinedAt);
        });
    }
}

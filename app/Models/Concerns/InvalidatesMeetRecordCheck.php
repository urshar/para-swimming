<?php

namespace App\Models\Concerns;

use App\Models\Meet;
use Illuminate\Database\Eloquent\Model;

/**
 * Setzt den Prüfstatus des Rekordchecks (meets.records_checked_at) der betroffenen Veranstaltung zurück, sobald sich
 * ein Ergebnis rekordrelevant ändert: Anlegen, Löschen und Bearbeiten eines der Felder aus
 * recordRelevantAttributes(). Punkte, Platz, Lauf/Bahn und die Rekord-Flags gehören bewusst nicht dazu — die
 * ändert u. a. der Rekordcheck selbst (Entscheidung Erik, 11.11.2026).
 *
 * Läuft über Model-Events; Massen-Updates per DB::table() (z. B. AthleteMergeService) müssen
 * Meet::invalidateRecordCheck() selbst aufrufen.
 *
 * @mixin Model
 */
trait InvalidatesMeetRecordCheck
{
    public static function bootInvalidatesMeetRecordCheck(): void
    {
        static::created(fn (self $model) => Meet::invalidateRecordCheck($model->recordCheckMeetIds()));
        static::deleted(fn (self $model) => Meet::invalidateRecordCheck($model->recordCheckMeetIds()));
        static::updated(function (self $model) {
            if ($model->wasChanged($model->recordRelevantAttributes())) {
                Meet::invalidateRecordCheck($model->recordCheckMeetIds());
            }
        });
    }

    /**
     * Spalten, deren Änderung einen neuen Rekordcheck nötig macht.
     *
     * @return list<string>
     */
    abstract protected function recordRelevantAttributes(): array;

    /**
     * Veranstaltungen, deren Prüfstatus zurückgesetzt wird.
     *
     * @return list<int>
     */
    abstract protected function recordCheckMeetIds(): array;
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vereins-History eines Athleten (Ummeldungen).
     *
     * Regeln:
     * - Pro Athlet darf immer nur 1 Eintrag mit is_active = true existieren.
     * - Bei Vereinswechsel: aktiven Eintrag schließen (left_at setzen, is_active = false),
     *   neuen Eintrag anlegen (is_active = true).
     * - club_id auf athletes bleibt als Convenience-Feld synchron.
     *
     * Warum die History? Rekordprüfung muss wissen, welchem Verein ein Athlet
     * zum Zeitpunkt des Rekords (set_date) angehört hat.
     */
    public function up(): void
    {
        Schema::create('athlete_club_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->restrictOnDelete();

            $table->date('joined_at');
            $table->date('left_at')->nullable(); // null = aktuell aktiv

            $table->boolean('is_active')->default(false);

            $table->text('notes')->nullable(); // Grund der Ummeldung etc.

            $table->timestamps();

            $table->index('athlete_id');
            $table->index('club_id');
            $table->index('is_active');
            $table->index(['athlete_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_club_history');
    }
};

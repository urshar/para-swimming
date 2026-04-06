<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Level-History eines Athleten (ÖBSV-Einstufung).
     *
     * Jede Änderung wird protokolliert: neuer Level, Datum, und welcher User
     * die Änderung vorgenommen hat.
     */
    public function up(): void
    {
        Schema::create('athlete_level_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();

            // User, der die Änderung vorgenommen hat (users-Tabelle existiert bereits durch Laravel)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('level', 50);        // z.B. "1", "2", "Elite", "Talent" — vom ÖBSV definiert
            $table->string('previous_level', 50)->nullable(); // Vorheriger Level zur Nachvollziehbarkeit

            $table->date('changed_at');         // Datum der Einstufung (kann vom created_at abweichen)
            $table->text('notes')->nullable();  // Begründung / Kommentar

            $table->timestamps();

            $table->index('athlete_id');
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_level_history');
    }
};

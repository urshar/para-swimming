<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relay_entry_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('relay_entry_id')
                ->constrained('relay_entries')
                ->cascadeOnDelete();

            $table->foreignId('athlete_id')
                ->constrained('athletes')
                ->cascadeOnDelete();

            // Position in der Staffel (1–4), nullable für ungeordnete Meldungen
            $table->unsignedTinyInteger('position')->nullable();

            // Sportklasse des Mitglieds zum Meldezeitpunkt (z.B. "S9", "SB8")
            $table->string('sport_class', 10)->nullable();

            $table->timestamps();

            // Eindeutigkeit: ein Athlet pro Staffelmeldung nur einmal
            $table->unique(['relay_entry_id', 'athlete_id']);

            // Eindeutigkeit: eine Position pro Staffelmeldung (wenn gesetzt)
            // Partial unique über Index – wird via DB-Ebene erzwungen, wo möglich
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relay_entry_members');
    }
};

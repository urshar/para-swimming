<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datum und Startzeit je Abschnitt (LENEX SESSION@date / SESSION@daytime). Die Zuordnung zu den Disziplinen
     * läuft über (meet_id, number) = (swim_events.meet_id, swim_events.session_number) — swim_events bleibt
     * unverändert. Fehlt ein Eintrag, gilt der Abschnitt als ohne eigenes Datum.
     */
    public function up(): void
    {
        Schema::create('meet_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->date('date')->nullable();
            $table->time('daytime')->nullable();
            $table->timestamps();

            $table->unique(['meet_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meet_sessions');
    }
};

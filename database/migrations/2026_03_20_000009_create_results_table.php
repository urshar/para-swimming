<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * results — basierend auf LENEX 3.0 Element RESULT
     *
     * RESULT Attribute:
     *   swimtime     → Pflicht, Swim-Time-Format
     *   status       → EXH, DSQ, DNS, DNF, SICK, WDR
     *   handicap     → Sport-Klasse für dieses Ergebnis (kann abweichen)
     *   points       → Punkte nach Punktesystem des Meets
     *   reactiontime → Reaktionszeit in Hundertstelsekunden
     *   comment      → Zusatzkommentar (z.B. DSQ-Grund, Rekord-Hinweis)
     *   heatid / lane → Lauf und Bahn
     *   resultid     → unique pro Meet (LENEX-intern)
     *
     * Splits → eigene Tabelle result_splits (000009b)
     */
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('swim_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->restrictOnDelete();

            // Schwimmzeit in Hundertstelsekunden (null = keine Zeit, DNS/DNF/DSQ)
            $table->integer('swim_time')->nullable();

            // LENEX RESULT.status
            $table->enum('status', [
                'EXH',   // Exhibition swim
                'DSQ',   // Disqualified
                'DNS',   // Did not start
                'DNF',   // Did not finish
                'SICK',  // Sick
                'WDR',   // Withdrawn
            ])->nullable(); // null = reguläres Ergebnis

            // LENEX RESULT.handicap — Sport-Klasse für dieses Ergebnis
            $table->string('sport_class', 15)->nullable();

            // Para-Punkte (LENEX RESULT.points)
            $table->integer('points')->nullable();

            // Lauf und Bahn
            $table->integer('heat')->nullable();
            $table->integer('lane')->nullable();

            // Platzierung
            $table->integer('place')->nullable();

            // LENEX RESULT.reactiontime (Hundertstelsekunden, kann negativ sein → Fehlstart)
            $table->integer('reaction_time')->nullable();

            // LENEX RESULT.comment (z.B. DSQ-Begründung, "Ratification pending")
            $table->string('comment')->nullable();

            // Rekord-Flags (aus LENEX Import, werden auch vom RecordCheckerService gesetzt)
            $table->boolean('is_world_record')->default(false);
            $table->boolean('is_european_record')->default(false);
            $table->boolean('is_national_record')->default(false);

            // LENEX resultid — nicht unique in unserer DB (verschiedene Meets)
            // Nur für Import-Referenz innerhalb eines Meets
            $table->string('lenex_result_id')->nullable();

            $table->timestamps();

            $table->unique(['meet_id', 'swim_event_id', 'athlete_id', 'heat', 'lane']);
            $table->index(['swim_event_id', 'swim_time']);
            $table->index('sport_class');
            $table->index('athlete_id');
            $table->index('club_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};

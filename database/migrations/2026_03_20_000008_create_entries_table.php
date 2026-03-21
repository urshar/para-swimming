<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * entries — basierend auf LENEX 3.0 Element ENTRY
     *
     * ENTRY Attribute:
     *   entrytime    → Meldezeit im Swim-Time-Format ("NT" wenn keine Zeit)
     *   entrycourse  → Bahnlänge der Meldezeit (kann von Meet-Course abweichen)
     *   status       → EXH, RJC, SICK, WDR
     *   handicap     → Sport-Klasse für diesen Start (kann von Athlet-Klasse abweichen)
     *   heatid       → Referenz auf Heat (int, kein FK da Heats noch nicht normalisiert)
     *   lane         → Bahnnummer
     *   eventid      → Referenz auf EVENT (→ swim_event_id)
     *
     * Kombination eventid + heatid + lane muss unique sein (laut LENEX Spec)
     */
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('swim_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->restrictOnDelete(); // meldender Verein

            // Meldezeit in Hundertstelsekunden (NT = null)
            $table->integer('entry_time')->nullable();

            // "NT" oder andere Codes wenn keine Zeit vorhanden
            $table->string('entry_time_code', 10)->nullable();

            // LENEX ENTRY.entrycourse — Bahnlänge der Meldezeit (alle LENEX 3.0 Werte)
            $table->enum('entry_course', [
                'LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33',
                'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN',
            ])->nullable();

            // LENEX ENTRY.status
            $table->enum('status', [
                'EXH',   // Exhibition swim
                'RJC',   // Rejected entry
                'SICK',  // Athlete is sick
                'WDR',   // Withdrawn
            ])->nullable();

            // LENEX ENTRY.handicap — Sport-Klasse für diesen spezifischen Start
            // Kann von der allgemeinen Athlet-Klasse abweichen
            $table->string('sport_class', 15)->nullable();

            // Lauf und Bahn (aus Seeding)
            $table->integer('heat')->nullable();
            $table->integer('lane')->nullable();

            $table->timestamps();

            // LENEX: eventid + heatid + lane muss unique sein
            $table->unique(['swim_event_id', 'heat', 'lane'], 'entries_event_heat_lane_unique');
            // Pro Athlet nur ein Entry pro Event
            $table->unique(['meet_id', 'swim_event_id', 'athlete_id']);

            $table->index('athlete_id');
            $table->index('club_id');
            $table->index('swim_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};

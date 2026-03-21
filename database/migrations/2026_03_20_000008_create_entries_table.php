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
     *   heatid       → Referenz auf Heat (nullable bis zum Seeding)
     *   lane         → Bahnnummer (nullable bis zum Seeding)
     *
     * UNIQUE Constraints:
     *   - Pro Athlet nur ein Entry pro Event → [meet_id, swim_event_id, athlete_id]
     *   - Heat+Lane unique NUR wenn beide gesetzt (kein unique auf nullable Kombination,
     *     da MySQL NULL=NULL im unique constraint → würde unseeded Imports blockieren)
     */
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('swim_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->restrictOnDelete(); // meldender Verein

            // Meldezeit in Hundertstelsekunden (null = NT)
            $table->integer('entry_time')->nullable();

            // "NT" oder andere Codes wenn keine Zeit vorhanden
            $table->string('entry_time_code', 10)->nullable();

            // LENEX ENTRY.entrycourse — Bahnlänge der Meldezeit (alle LENEX 3.0 Werte)
            $table->enum('entry_course', [
                'LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33',
                'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN',
            ])->nullable();

            // LENEX ENTRY.status (null = reguläre Meldung)
            $table->enum('status', [
                'EXH',   // Exhibition swim
                'RJC',   // Rejected entry
                'SICK',  // Athlete is sick
                'WDR',   // Withdrawn
            ])->nullable();

            // LENEX ENTRY.handicap — Sport-Klasse für diesen spezifischen Start
            $table->string('sport_class', 15)->nullable();

            // Lauf und Bahn — nullable bis zum Seeding
            $table->integer('heat')->nullable();
            $table->integer('lane')->nullable();

            $table->timestamps();

            // Pro Athlet nur ein Entry pro Event — das ist der wichtige unique constraint
            $table->unique(['meet_id', 'swim_event_id', 'athlete_id']);

            // heat+lane unique wird NICHT als DB-Constraint gesetzt, da beide nullable sind.
            // MySQL behandelt NULL=NULL im unique constraint → mehrere unseeded
            // Entries (heat=null, lane=null) würden blockiert werden.
            // Diese Prüfung erfolgt stattdessen auf Applikationsebene im EntryService.
            $table->index(['swim_event_id', 'heat', 'lane']);

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

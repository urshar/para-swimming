<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * meets — basierend auf LENEX 3.0 Element MEET
     *
     * course-Werte laut LENEX 3.0 Sektion 6.4:
     *   LCM, SCM, SCY, SCM16, SCM20, SCM33, SCY20, SCY27, SCY33, SCY36, OPEN
     *
     * timing-Werte:
     *   AUTOMATIC, SEMIAUTOMATIC, MANUAL3, MANUAL2, MANUAL1
     *
     * entry type-Werte:
     *   OPEN (für alle Clubs), INVITATION (nur eingeladene Clubs)
     *
     * status-Werte (ab 2025):
     *   ENTRIES, SEEDED, RUNNING, OFFICIAL
     */
    public function up(): void
    {
        Schema::create('meets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city', 100)->nullable();
            $table->foreignId('nation_id')->constrained()->restrictOnDelete();

            // LENEX 3.0 Sektion 6.4 — alle gültigen course-Werte
            $table->enum('course', [
                'LCM',    // Long Course Meters (50m)
                'SCM',    // Short Course Meters (25m)
                'SCY',    // Short Course Yards
                'SCM16',  // 16m pool
                'SCM20',  // 20m pool
                'SCM33',  // 33m pool
                'SCY20',  // 20 yards
                'SCY27',  // 27 yards
                'SCY33',  // 33 yards
                'SCY36',  // 36 yards
                'OPEN',   // Open water
            ])->default('LCM');

            $table->date('start_date');
            $table->date('end_date')->nullable();
            // Meldeschluss: nach diesem Datum dürfen Vereins-User keine
            // Meldungen mehr anlegen, bearbeiten oder löschen.
            // Admins sind ausgenommen.
            // NULL = kein Meldeschluss gesetzt.
            $table->date('entries_deadline')->nullable();
            $table->string('organizer')->nullable();
            $table->integer('altitude')->default(0);

            // LENEX 3.0 MEET.timing
            $table->enum('timing', [
                'AUTOMATIC',
                'SEMIAUTOMATIC',
                'MANUAL3',
                'MANUAL2',
                'MANUAL1',
            ])->default('AUTOMATIC');

            // LENEX 3.0 MEET.entrytype
            $table->enum('entry_type', ['OPEN', 'INVITATION'])->nullable();

            // LENEX 3.0 MEET.status
            $table->enum('lenex_status', [
                'ENTRIES',
                'SEEDED',
                'RUNNING',
                'OFFICIAL',
            ])->nullable();

            // Applikations-Flag: Clubs können Athleten melden
            // entspricht MEET.entrytype = OPEN in LENEX
            $table->boolean('is_open')->default(false);

            // Global unique ID von swimrankings.net
            $table->string('swrid')->nullable();

            // LENEX Import-Referenz: nicht unique — Meetmanager vergibt pro Export neu
            $table->string('lenex_meet_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('nation_id');
            $table->index('start_date');
            $table->index('lenex_meet_id');
            $table->index('swrid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meets');
    }
};

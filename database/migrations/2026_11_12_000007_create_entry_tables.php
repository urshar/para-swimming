<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meldungen (Einzel und Staffel) und Qualifikationen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->foreignId('swim_event_id')->index()->constrained('swim_events')->cascadeOnDelete();
            $table->foreignId('athlete_id')->index()->constrained('athletes')->cascadeOnDelete();
            // meldender Verein
            $table->foreignId('club_id')->index()->constrained('clubs')->restrictOnDelete();
            // Meldezeit in Hundertstelsekunden (null = NT)
            $table->integer('entry_time')->nullable();
            // "NT" oder andere Codes wenn keine Zeit vorhanden
            $table->string('entry_time_code', 10)->nullable();
            // LENEX ENTRY.entrycourse — Bahnlänge der Meldezeit (alle LENEX 3.0 Werte)
            $table->enum('entry_course', ['LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33', 'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN'])->nullable();
            // LENEX ENTRY.status (null = reguläre Meldung)
            $table->enum('status', ['EXH', 'RJC', 'SICK', 'WDR'])->nullable();
            $table->boolean('is_late_entry')->default(false);
            // LENEX ENTRY.handicap — Sport-Klasse für diesen spezifischen Start
            $table->string('sport_class', 15)->nullable();
            // Lauf und Bahn — nullable bis zum Seeding
            $table->integer('heat')->nullable();
            $table->integer('lane')->nullable();
            $table->timestamps();

            $table->unique(['meet_id', 'swim_event_id', 'athlete_id']);
            $table->index(['swim_event_id', 'heat', 'lane']);
        });

        Schema::create('relay_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->foreignId('swim_event_id')->constrained('swim_events')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->unsignedTinyInteger('relay_number')->nullable();
            $table->string('name', 50)->nullable();
            // Berechnete Staffelklasse (z.B. "S20", "S34", "S49", "S14", "S15", "S21")
            $table->string('relay_class', 10)->nullable();
            // Meldezeit in Millisekunden (analog zu entries.entry_time)
            $table->unsignedInteger('entry_time')->nullable();
            // NT, A, B, etc.
            $table->string('entry_time_code', 10)->nullable();
            // LCM / SCM
            $table->string('entry_course', 3)->nullable();
            // Status: pending / confirmed / withdrawn
            $table->string('status', 20)->default('pending');
            $table->boolean('is_late_entry')->default(false);
            $table->boolean('is_exhibition')->default(false);
            $table->timestamps();
        });

        Schema::create('relay_entry_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relay_entry_id')->constrained('relay_entries')->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();
            // Position in der Staffel (1–4), nullable für ungeordnete Meldungen
            $table->unsignedTinyInteger('position')->nullable();
            // Sportklasse des Mitglieds zum Meldezeitpunkt (z.B. "S9", "SB8")
            $table->string('sport_class', 10)->nullable();
            $table->timestamps();

            $table->unique(['relay_entry_id', 'athlete_id']);
        });

        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            // Das ÖSTM & ÖM-Meet, für das qualifiziert wurde.
            $table->foreignId('meet_id')->nullable()->constrained('meets')->nullOnDelete();
            $table->foreignId('qualifying_time_list_id')->constrained('qualifying_time_lists')->cascadeOnDelete();
            $table->foreignId('qualifying_time_id')->constrained('qualifying_times')->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();
            // Das konkrete (beste) Ergebnis, mit dem qualifiziert wurde.
            $table->foreignId('result_id')->constrained('results')->cascadeOnDelete();
            // Snapshot-Felder — bewusst NICHT live aus athlete/club/result nachgeladen.
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->string('sport_class', 15);
            $table->unsignedInteger('swim_time_centiseconds');
            $table->integer('points')->nullable();
            // Startdatum des Meets, bei dem qualifiziert wurde
            $table->date('qualified_at');
            $table->timestamps();

            $table->unique(['qualifying_time_list_id', 'athlete_id', 'qualifying_time_id'], 'qualifications_unique_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualifications');
        Schema::dropIfExists('relay_entry_members');
        Schema::dropIfExists('relay_entries');
        Schema::dropIfExists('entries');
    }
};

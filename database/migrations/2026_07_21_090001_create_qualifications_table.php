<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * qualifications — die ermittelten qualifizierten Schwimmer pro ÖSTM &
     * ÖM-Veranstaltung (Phase 4 + 5 der Spec "Richtzeiten ÖSTM & ÖM").
     *
     * Snapshot-Tabelle (Erik, 2026-07-17 bestätigt): sport_class, club_id,
     * points und swim_time_centiseconds werden zum Berechnungszeitpunkt
     * eingefroren, damit spätere Korrekturen an Results/Athlete/Club (z.B.
     * Vereinswechsel, nachträgliche Punkte-Neuberechnung) eine bereits
     * gespeicherte Qualifikationsliste nicht rückwirkend verändern
     * (Phase 5 — Historisierung: "Spätere Ergebnisimporte dürfen bereits
     * gespeicherte Listen nicht verändern").
     *
     * Eine Neuberechnung ersetzt alle Zeilen für das jeweilige Meet
     * vollständig (kein Merge) — siehe QualificationDeterminationService.
     */
    public function up(): void
    {
        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();

            // Das ÖSTM & ÖM-Meet, für das qualifiziert wurde.
            $table->foreignId('meet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qualifying_time_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qualifying_time_id')->constrained()->cascadeOnDelete();

            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            // Das konkrete (beste) Ergebnis, mit dem qualifiziert wurde.
            $table->foreignId('result_id')->constrained()->cascadeOnDelete();

            // Snapshot-Felder — bewusst NICHT live aus athlete/club/result nachgeladen.
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sport_class', 15);
            $table->unsignedInteger('swim_time_centiseconds');
            $table->integer('points')->nullable();
            $table->date('qualified_at'); // Startdatum des Meets, bei dem qualifiziert wurde

            $table->timestamps();

            $table->unique(['meet_id', 'athlete_id', 'qualifying_time_id'], 'qualifications_unique_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualifications');
    }
};

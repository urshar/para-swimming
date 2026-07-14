<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tageswertung (Punkt 9 der Spec) — ein Snapshot-Datensatz pro Athlet und
     * Cup-Meet: das beste gültige Ergebnis des Tages, bereits der passenden
     * Sportklassengruppe zugeordnet (inkl. Top-Gruppe, siehe GroupResolverService).
     *
     * Bewusst KEINE Altersgruppe hier — die Tageswertung wird laut Erik nur
     * nach Geschlecht + Sportklassengruppe getrennt. Die Altersgruppe kommt
     * erst bei der Gesamtwertung dazu (siehe cup_overall_results).
     *
     * Wird per DailyRankingService::calculateForMeet() als expliziter
     * Berechnungslauf befüllt (Snapshot), nicht live aus results berechnet.
     */
    public function up(): void
    {
        Schema::create('cup_daily_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->restrictOnDelete();

            // Das konkrete Result, das als bestes Ergebnis des Tages gewertet wurde.
            $table->foreignId('result_id')->constrained()->cascadeOnDelete();

            $table->foreignId('sport_class_group_id')->constrained()->restrictOnDelete();
            $table->enum('gender', ['M', 'F']);

            $table->unsignedInteger('points');

            $table->timestamp('calculated_at');
            $table->timestamps();

            // Ein Athlet erhält pro Cup-Meet genau eine Tageswertungs-Zeile
            // (sein bestes Ergebnis des Tages).
            $table->unique(['cup_id', 'meet_id', 'athlete_id'], 'cup_daily_results_unique');
            $table->index(['meet_id', 'gender', 'sport_class_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cup_daily_results');
    }
};

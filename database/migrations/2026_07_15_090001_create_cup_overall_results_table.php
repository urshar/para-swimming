<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gesamtwertung (Punkt 10 der Spec) — ein Snapshot-Datensatz pro Athlet,
     * Geschlecht und Sportklassengruppe innerhalb eines Cup-Jahres: Summe der
     * besten X Tageswertungen (cup.best_of_count), zusätzlich nach
     * Altersgruppe getrennt (Erik: nur bei der Gesamtwertung, nicht bei der
     * Tageswertung).
     *
     * Wird per OverallRankingService::calculateForCup() als expliziter
     * Berechnungslauf befüllt (Snapshot), nicht live berechnet.
     */
    public function up(): void
    {
        Schema::create('cup_overall_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->restrictOnDelete();

            $table->foreignId('sport_class_group_id')->constrained()->restrictOnDelete();
            $table->enum('gender', ['M', 'F']);

            // nullable — falls kein Geburtsdatum hinterlegt ist, siehe GroupResolverService::resolveAgeGroup()
            $table->foreignId('age_group_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('total_points');
            $table->unsignedTinyInteger('rounds_counted'); // wie viele Tageswertungen tatsächlich eingeflossen sind

            // IDs der cup_daily_results-Zeilen, die in die Summe eingeflossen sind (Transparenz/Nachvollziehbarkeit).
            $table->json('counted_daily_result_ids');

            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['cup_id', 'athlete_id', 'gender', 'sport_class_group_id'], 'cup_overall_results_unique');
            $table->index(['cup_id', 'gender', 'sport_class_group_id', 'age_group_id'], 'cup_overall_results_bracket_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cup_overall_results');
    }
};

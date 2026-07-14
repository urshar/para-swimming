<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saison-Klassifizierung für die Top-Gruppe (Punkt 8 der Spec, konkretisiert
     * durch Erik): zu Saisonbeginn wird pro Athlet geprüft, ob er in den beiden
     * Kalenderjahren VOR dem Cup-Jahr bei einem Cup-Meet mehr als die
     * Punktgrenze erreicht hat (Auf-/Abstieg). Nationalkader-Athleten bleiben
     * davon unabhängig immer in der Top-Gruppe.
     *
     * Bewusst getrennt von der "ausländischer Verein"-Regel (Punkt 6) — die
     * bleibt eine sofortige, ergebnisbezogene Prüfung in GroupResolverService,
     * da sie keine Saisonhistorie braucht.
     *
     * Wird per TopGroupClassificationService::calculateForCup() als expliziter
     * Berechnungslauf befüllt (Snapshot) — MUSS vor der Tageswertung des
     * jeweiligen Cups laufen, da DailyRankingService darauf zugreift.
     */
    public function up(): void
    {
        Schema::create('cup_top_group_classifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_top_group');
            $table->enum('reason', ['KADER', 'POINTS_HISTORY'])->nullable(); // null = nicht in der Top-Gruppe

            // bester gefundener Punktewert im Rückblickzeitraum, nur zur Nachvollziehbarkeit (kann null sein).
            $table->unsignedInteger('reference_points')->nullable();

            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['cup_id', 'athlete_id'], 'cup_top_group_class_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cup_top_group_classifications');
    }
};

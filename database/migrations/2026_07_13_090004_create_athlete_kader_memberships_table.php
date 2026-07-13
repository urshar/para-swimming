<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nationalkader-Zugehörigkeit eines Athleten (Punkt 3 der Spec).
     *
     * valid_from/valid_until sind bereits vorbereitet für spätere zeitlich
     * begrenzte Zugehörigkeiten (optionale Anforderung der Spec). Für die
     * Cupwertung selbst zählt vorerst nur: existiert zum Stichtag ein
     * gültiger Eintrag (siehe AthleteKaderMembership::scopeActiveOn()).
     */
    public function up(): void
    {
        Schema::create('athlete_kader_memberships', function (Blueprint $table) {
            $table->id();

            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kader_type_id')->constrained()->restrictOnDelete();

            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('athlete_id');
            $table->index(['valid_from', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_kader_memberships');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dublettenvorschläge, die ein Admin als "kein Duplikat" markiert hat (z. B. Geschwister mit gleichem Geburtsdatum).
 * Ein Paar steht genau einmal da: athlete_id ist immer die kleinere ID. Wird einer der beiden gelöscht oder
 * zusammengeführt, entfällt die Markierung (cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('athlete_duplicate_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('other_athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['athlete_id', 'other_athlete_id'], 'athlete_duplicate_dismissals_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_duplicate_dismissals');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * qualifying_excluded_disciplines — Bewerbe (Basiswert-Disziplinen), die bei
     * ÖSTM & ÖM nicht ausgetragen werden und daher von der automatischen
     * Richtzeiten-Berechnung ausgenommen sind (z.B. alle 25m-Bewerbe, 800m/1500m
     * Freistil — Stand Erik, 2026-07-17). Admin-verwaltbar statt fix im Code,
     * da sich das Wettkampfprogramm künftig ändern kann.
     *
     * Bewusst als Referenz auf base_time_disciplines statt eigener
     * Stroke/Distanz-Spalten, um keine Bewerbsdefinition zu duplizieren.
     * Gilt global (nicht pro Richtzeitenliste/Jahr), da das ÖSTM & ÖM-Wettkampfprogramm jahresübergreifend gleich ist.
     */
    public function up(): void
    {
        Schema::create('qualifying_excluded_disciplines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_time_discipline_id')
                ->unique()
                ->constrained('base_time_disciplines')
                ->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualifying_excluded_disciplines');
    }
};

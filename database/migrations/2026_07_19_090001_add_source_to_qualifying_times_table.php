<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Markiert, ob eine Richtzeiten-Zeile automatisch berechnet (Phase 2) oder
     * manuell gesetzt/überschrieben wurde. Bei einer Neuberechnung bleiben
     * MANUAL-Zeilen standardmäßig unangetastet, damit Admin-Korrekturen nicht
     * versehentlich verloren gehen (siehe QualifyingTimeCalculationService).
     *
     * Default MANUAL, damit die in Phase 1 bereits manuell angelegten Zeilen
     * korrekt eingeordnet bleiben.
     */
    public function up(): void
    {
        Schema::table('qualifying_times', function (Blueprint $table) {
            $table->enum('source', ['MANUAL', 'CALCULATED'])->default('MANUAL')->after('value_centiseconds');
        });
    }

    public function down(): void
    {
        Schema::table('qualifying_times', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};

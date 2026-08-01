<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * meet_point_system — welche Punktesysteme bei einer Veranstaltung berechnet werden.
     *
     * Bewusst als Pivot statt als zusätzliche Spalten auf meets: die Zahl der Punktesysteme
     * ist offen, und die Zuordnung trägt mit wps_point_version_id eine eigene Eigenschaft.
     *
     * meets verwendet SoftDeletes — der Cascade greift daher erst beim endgültigen Löschen.
     * Beim Soft-Delete bleibt die Zuordnung erhalten, was gewollt ist (Wiederherstellung).
     */
    public function up(): void
    {
        Schema::create('meet_point_system', function (Blueprint $table) {
            $table->id();

            $table->foreignId('meet_id')
                ->constrained('meets')
                ->cascadeOnDelete();

            $table->foreignId('point_system_id')
                ->constrained('point_systems')
                ->cascadeOnDelete();

            // Übersteuert die automatische Versionszuordnung nach Wettkampfdatum.
            // null = automatisch ermitteln. Nur für das Punktesystem WPS relevant.
            $table->foreignId('wps_point_version_id')
                ->nullable()
                ->constrained('wps_point_versions')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['meet_id', 'point_system_id'], 'meet_point_system_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meet_point_system');
    }
};

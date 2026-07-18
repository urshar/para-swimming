<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Der Qualifikationszeitraum wird direkt an der Richtzeitenliste gepflegt,
     * statt automatisch aus einem verknüpften ÖSTM & ÖM-Meet abgeleitet zu
     * werden (Erik, 2026-07-18: das Ziel-Meet des Folgejahres steht zum
     * Zeitpunkt der Qualifikationsermittlung oft noch nicht fest — der
     * Zeitraum beginnt mit der bereits stattgefundenen Vorjahres-ÖSTM & ÖM
     * und das Ende steht erst fest, sobald der neue Termin bekannt ist).
     *
     * qualification_period_end ist bewusst jederzeit änderbar ("variabel")
     * und wird bei jeder Neuberechnung neu ausgewertet.
     */
    public function up(): void
    {
        Schema::table('qualifying_time_lists', function (Blueprint $table) {
            $table->date('qualification_period_start')->nullable()->after('is_active');
            $table->date('qualification_period_end')->nullable()->after('qualification_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('qualifying_time_lists', function (Blueprint $table) {
            $table->dropColumn(['qualification_period_start', 'qualification_period_end']);
        });
    }
};

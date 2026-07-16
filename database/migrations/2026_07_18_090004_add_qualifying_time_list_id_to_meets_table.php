<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ordnet ein Meet optional genau einer Richtzeitenliste zu und markiert
     * es damit als das ÖSTM & ÖM-Meet des jeweiligen Wettkampfjahres
     * (analog zu meets.cup_id für die Cup-Wertung). Wird für die
     * automatische Ermittlung des Qualifikationszeitraums (Phase 4) benötigt.
     */
    public function up(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->foreignId('qualifying_time_list_id')->nullable()->after('cup_id')
                ->constrained('qualifying_time_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('qualifying_time_list_id');
        });
    }
};

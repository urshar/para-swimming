<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internationale Veranstaltungen: Die Plätze aus der Ergebnisdatei (z. B. EM-Platz) bleiben erhalten und werden nicht
 * aus den importierten Ergebnissen neu berechnet (ScoringGroupService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->boolean('keep_file_places')->default(false)->after('wps_approved');
        });
    }

    public function down(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->dropColumn('keep_file_places');
        });
    }
};

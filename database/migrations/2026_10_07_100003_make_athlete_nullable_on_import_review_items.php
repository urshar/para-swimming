<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prüfliste: Einträge ohne Athlet — "Abweichung zur Rekordliste" (Staffeln) und "Staffelrekord ohne Verein".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_review_items', function (Blueprint $table) {
            $table->foreignId('athlete_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Nicht rückführbar, sobald Einträge ohne Athlet existieren.
    }
};

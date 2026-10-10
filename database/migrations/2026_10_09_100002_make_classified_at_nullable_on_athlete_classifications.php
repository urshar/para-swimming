<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aus dem Splash Team Manager übernommene Klassifizierungen haben nicht immer ein Datum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athlete_classifications', function (Blueprint $table) {
            $table->date('classified_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Nicht rückführbar, sobald Klassifizierungen ohne Datum existieren.
    }
};

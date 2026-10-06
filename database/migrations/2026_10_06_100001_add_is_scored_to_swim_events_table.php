<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rahmenbewerbe (z. B. Schnupperbewerbe für nicht klassifizierte Schwimmer) gehören zur Veranstaltung und stehen im
 * LENEX, werden aber nicht gewertet: Ergebnisse und Meldungen werden nicht importiert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swim_events', function (Blueprint $table) {
            $table->boolean('is_scored')->default(true)->after('sport_classes');
        });
    }

    public function down(): void
    {
        Schema::table('swim_events', function (Blueprint $table) {
            $table->dropColumn('is_scored');
        });
    }
};

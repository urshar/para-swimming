<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LENEX sieht für SWIMSTYLE code höchstens 6 Zeichen vor, Splash schreibt aber auch längere (z. B. "4x50fun" für
 * eine Fun-Staffel). Der Code wird unverändert übernommen, statt den Import abbrechen zu lassen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swim_events', function (Blueprint $table) {
            $table->string('style_code', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('swim_events', function (Blueprint $table) {
            $table->string('style_code', 6)->nullable()->change();
        });
    }
};

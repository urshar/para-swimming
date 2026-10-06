<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LENEX RELAY number (Mannschaft 1, 2 ... des Vereins im Bewerb) — Abgleich beim erneuten Import der
 * Staffelmeldungen und Nummer im Meldeexport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relay_entries', function (Blueprint $table) {
            $table->unsignedTinyInteger('relay_number')->nullable()->after('club_id');
        });
    }

    public function down(): void
    {
        Schema::table('relay_entries', function (Blueprint $table) {
            $table->dropColumn('relay_number');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Erik: pro Wertungsgruppe (Sportklassengruppe) soll wählbar sein, ob
     * Damen und Herren gemeinsam oder getrennt gewertet werden — Default
     * getrennt (false). Wirkt sich NUR auf die Rangliste/Anzeige aus (eine
     * gemeinsame sortierte Liste statt zwei getrennter); die Punkteberechnung
     * selbst bleibt unverändert, da sie ohnehin pro Athlet unabhängig vom
     * Geschlecht erfolgt.
     */
    public function up(): void
    {
        Schema::table('cup_group_settings', function (Blueprint $table) {
            $table->boolean('gender_combined')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('cup_group_settings', function (Blueprint $table) {
            $table->dropColumn('gender_combined');
        });
    }
};

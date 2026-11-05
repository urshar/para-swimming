<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bei Staffeln ist die Altersgruppe die Summe der Alter (Masters z. B. 320-359), das passt nicht in ein
     * TINYINT (bis 255).
     */
    public function up(): void
    {
        Schema::table('scoring_groups', function (Blueprint $table) {
            $table->unsignedSmallInteger('age_min')->nullable()->change();
            $table->unsignedSmallInteger('age_max')->nullable()->change();
        });
    }

    /** Scheitert auf MySQL, solange Gruppen mit Werten über 255 existieren. */
    public function down(): void
    {
        Schema::table('scoring_groups', function (Blueprint $table) {
            $table->unsignedTinyInteger('age_min')->nullable()->change();
            $table->unsignedTinyInteger('age_max')->nullable()->change();
        });
    }
};

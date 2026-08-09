<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kennzeichnung WPS-anerkannter Wettkämpfe.
     *
     * Nur Wettkämpfe, die von World Para Swimming sanktioniert sind, liefern gültige
     * Qualifikationszeiten. Ohne dieses Merkmal würde die Qualifikantenliste Zeiten aus
     * beliebigen Wettkämpfen als Nachweis ausweisen.
     *
     * Default false, ausdrücklich auch für den Altbestand: Ein Default true behauptete über
     * jeden bestehenden Wettkampf eine Anerkennung, die niemand geprüft hat — und diese
     * Behauptung landete unbemerkt in der Nachweisliste. Eine leere, erklärte Liste ist
     * besser als eine volle, falsche. Die Qualifikantenliste weist deshalb aus, wie viele
     * Ergebnisse mangels Kennzeichnung ausgeschlossen wurden.
     */
    public function up(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->boolean('wps_approved')->default(false)->after('is_open');
            $table->string('wps_approved_note', 255)->nullable()->after('wps_approved');
        });
    }

    public function down(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->dropColumn(['wps_approved', 'wps_approved_note']);
        });
    }
};

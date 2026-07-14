<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * counted_daily_result_ids → counted_meet_ids.
     *
     * Bug: cup_daily_results-Zeilen werden bei jeder Neuberechnung der
     * Tageswertung gelöscht und neu angelegt (neue Auto-Increment-IDs). Wird
     * danach NICHT sofort auch die Gesamtwertung neu berechnet, zeigten die
     * in cup_overall_results gespeicherten IDs plötzlich ins Leere — die
     * farbliche Markierung "zählt zur Gesamtwertung" in der Anzeige griff
     * dadurch nicht mehr. meet_id ist dagegen stabil (ändert sich bei einer
     * Neuberechnung der Tageswertung nicht) und eignet sich daher besser als
     * Referenz.
     */
    public function up(): void
    {
        Schema::table('cup_overall_results', function (Blueprint $table) {
            $table->renameColumn('counted_daily_result_ids', 'counted_meet_ids');
        });
    }

    public function down(): void
    {
        Schema::table('cup_overall_results', function (Blueprint $table) {
            $table->renameColumn('counted_meet_ids', 'counted_daily_result_ids');
        });
    }
};

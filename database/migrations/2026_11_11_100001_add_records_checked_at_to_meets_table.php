<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prüfstatus des Rekordchecks je Veranstaltung (docs/open-points.md "Veranstaltungsliste: Rekordcheck-Knopf und
 * Prüfstatus"): Zeitpunkt des letzten erfolgreichen Checks, null = ungeprüft. Der Bestand bleibt ungeprüft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->timestamp('records_checked_at')->nullable()->after('medal_reduction_rule');
        });
    }

    public function down(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->dropColumn('records_checked_at');
        });
    }
};

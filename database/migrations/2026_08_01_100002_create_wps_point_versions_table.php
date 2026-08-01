<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * wps_point_versions — veröffentlichte World-Para-Swimming-Point-Score-Versionen.
     *
     * Versionen werden niemals überschrieben. Jede Veröffentlichung wird separat gespeichert,
     * Ergebnisse verweisen auf die verwendete Version. Damit bleiben historische Berechnungen
     * reproduzierbar.
     *
     * valid_from/valid_until übernehmen bewusst das Muster von base_time_versions
     * (scopeValidOn), damit die automatische Zuordnung nach Wettkampfdatum identisch
     * funktioniert wie bei den World-Aquatics-Basiswerten.
     */
    public function up(): void
    {
        Schema::create('wps_point_versions', function (Blueprint $table) {
            $table->id();

            // Anzeigename, z.B. "WPS 2026"
            $table->string('label', 100);

            $table->smallInteger('year')->unsigned();

            // Versionsbezeichnung des Herausgebers (Blatt "version control" der Quelldatei)
            $table->string('version', 20)->nullable();

            $table->string('source')->nullable();

            // false = abgeleitete/inoffizielle Zusammenstellung
            $table->boolean('official')->default(true);

            // active = verwendbar, archived = nur noch für historische Ergebnisse
            $table->string('status', 20)->default('active');

            $table->date('valid_from')->nullable();

            // null = bis auf Weiteres gültig
            $table->date('valid_until')->nullable();

            $table->timestamps();

            $table->unique(['year', 'version'], 'wps_point_versions_unique_release');
            $table->index('status');
            $table->index('valid_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wps_point_versions');
    }
};

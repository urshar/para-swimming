<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Erweitert results um die WPS-Punkte.
     *
     * Bewusst eigene Spalten statt Wiederverwendung von results.points (Entscheidung [E4] der
     * Spec): results.points enthält die World-Aquatics-Punkte und wird von DailyRankingService
     * (Cup-Wertung), den Richtzeiten, der Statistik und LenexExportService gelesen. Ein
     * Überschreiben durch WPS würde diese Module still verfälschen.
     *
     * wps_calculated_at dient der Erkennung veralteter Berechnungen, analog zum bestehenden
     * CupStalenessService.
     *
     * Die Migration ist rein additiv — bestehende Daten bleiben unverändert.
     */
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            // Achtung: nicht auf 1000 begrenzt. Der Gompertz-Parameter a beträgt in der
            // Version 2026 durchgängig 1200 — WPS-Punkte können über 1000 liegen.
            $table->integer('wps_points')->nullable()->after('points');

            $table->foreignId('wps_point_version_id')
                ->nullable()
                ->after('wps_points')
                ->constrained('wps_point_versions')
                ->nullOnDelete();

            // Der konkret verwendete Parametersatz — macht die Berechnung reproduzierbar.
            $table->foreignId('wps_point_parameter_id')
                ->nullable()
                ->after('wps_point_version_id')
                ->constrained('wps_point_parameters')
                ->nullOnDelete();

            // official = offiziell veröffentlichte Parameter (LCM)
            // estimated = aus LCM abgeleitete Parameter (SCM), nicht von WPS anerkannt
            $table->string('wps_calculation_type', 10)->nullable()->after('wps_point_parameter_id');

            $table->timestamp('wps_calculated_at')->nullable()->after('wps_calculation_type');

            $table->index('wps_points');
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            // dropForeign vor dem Entfernen der Spalten — sonst schlägt MySQL fehl, weil die
            // Spalte noch von einem Fremdschlüssel referenziert wird.
            $table->dropForeign(['wps_point_version_id']);
            $table->dropForeign(['wps_point_parameter_id']);

            $table->dropIndex(['wps_points']);

            $table->dropColumn([
                'wps_points',
                'wps_point_version_id',
                'wps_point_parameter_id',
                'wps_calculation_type',
                'wps_calculated_at',
            ]);
        });
    }
};

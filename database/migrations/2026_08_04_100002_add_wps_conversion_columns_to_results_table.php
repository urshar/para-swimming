<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hält fest, worauf geschätzte Kurzbahn-Punkte beruhen.
     *
     * wps_estimated_lcm_time ist dabei nicht nur Nachweis, sondern die fachlich eigentlich
     * gesuchte Größe (Spec §2.3): In Österreich wird ausschließlich Kurzbahn geschwommen,
     * international aber Langbahn. Die geschätzte Langbahnzeit lässt sich unmittelbar gegen
     * internationale Melde- und Finalzeiten halten — anders als eine Punktzahl allein.
     */
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            // Hundertstelsekunden, wie results.swim_time. Nur bei umgerechneten Zeiten gesetzt.
            $table->integer('wps_estimated_lcm_time')->nullable()->after('wps_calculation_type');

            $table->foreignId('wps_conversion_factor_id')
                ->nullable()
                ->after('wps_estimated_lcm_time')
                ->constrained('wps_scm_conversion_factors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            // dropForeign vor dropColumn — sonst schlägt MySQL fehl, weil die Spalte noch
            // von einem Fremdschlüssel referenziert wird.
            $table->dropForeign(['wps_conversion_factor_id']);

            $table->dropColumn(['wps_estimated_lcm_time', 'wps_conversion_factor_id']);
        });
    }
};

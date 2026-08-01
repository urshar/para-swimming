<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * wps_scm_derivations — Dokumentation, wie die SCM-Parameter einer Version abgeleitet wurden.
     *
     * World Para Swimming veröffentlicht keine offiziellen SCM-Parameter. Da SCM-Ergebnisse für
     * nationale Auswertungen benötigt werden, wird abgeleitet — die Ableitung muss aber
     * nachvollziehbar, versioniert und austauschbar bleiben. Feste Werte im Code sind unzulässig.
     *
     * Die eigentlichen abgeleiteten Parameter liegen in wps_point_parameters mit official = false.
     * Diese Tabelle hält ausschließlich die Metadaten der Ableitung.
     */
    public function up(): void
    {
        Schema::create('wps_scm_derivations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wps_point_version_id')
                ->constrained('wps_point_versions')
                ->cascadeOnDelete();

            // performance_ratio  = aus realen LCM/SCM-Vergleichsdaten (bevorzugt)
            // distance_adjustment = mathematische Streckenanpassung
            // federation_data     = veröffentlichte Verbandsdaten
            $table->string('conversion_method', 50);

            $table->string('source')->nullable();

            // high / medium / low — Qualitätsbewertung der Ableitung
            $table->string('confidence_level', 20)->nullable();

            // Anzahl der Vergleichspaare, auf denen die Ableitung beruht
            $table->integer('sample_size')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('wps_point_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wps_scm_derivations');
    }
};

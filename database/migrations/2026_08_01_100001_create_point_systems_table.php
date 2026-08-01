<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * point_systems — Registry der unterstützten Punktesysteme.
     *
     * Die Tabelle steuert in dieser Ausbaustufe ausschließlich, welche Punktesysteme einer
     * Veranstaltung zugeordnet werden können (Pivot meet_point_system). Eine generische
     * PointsEngine-Fassade, die anhand des Codes das Berechnungsverfahren lädt, ist bewusst
     * zurückgestellt (Entscheidung [E3] der Spec) — die Registry existiert jetzt schon, damit
     * sie später ohne Migration nachgerüstet werden kann.
     */
    public function up(): void
    {
        Schema::create('point_systems', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);

            // Eindeutiger Code, z.B. WA, WPS, OBSV1000
            $table->string('code', 20)->unique();

            $table->text('description')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_systems');
    }
};

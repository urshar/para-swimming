<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Altersgruppen für die Cupwertung (Punkt 5 der Spec).
     * Global administrierbar (nicht pro Cup-Jahr), damit zukünftig weitere
     * Gruppen (z.B. Senioren) ohne Änderungen an der Berechnungslogik
     * ergänzt werden können. Alter wird immer zum Wettkampfdatum berechnet.
     */
    public function up(): void
    {
        Schema::create('age_groups', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('name_de', 100);

            // Beide nullable = offenes Intervall (z.B. "Offen" hat nur min_age).
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('age_groups');
    }
};

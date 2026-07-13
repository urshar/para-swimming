<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sportklassengruppen für die Cupwertung (Punkt 7 der Spec): PI, VI, II,
     * T21, HI — plus die Top-Gruppe (Punkt 8), die als eigener Datensatz mit
     * is_virtual=true angelegt wird, da sie keine eigenen Sportklassen-
     * Mitglieder hat, sondern sich aus Kader/Punktgrenze/Ausland ergibt.
     *
     * Phase 1 bildet ausschließlich die Einzelbewerbs-Gruppen ab. Die
     * separate Staffel-Gruppierung (eigene Klassen wie R20/R34/R49) folgt
     * mit dem später geplanten Staffelcup.
     */
    public function up(): void
    {
        Schema::create('sport_class_groups', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20)->unique(); // PI, VI, II, T21, HI, TOP
            $table->string('name_de', 150);
            $table->boolean('is_virtual')->default(false); // true = Top-Gruppe (keine festen Sportklassen-Mitglieder)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_class_groups');
    }
};

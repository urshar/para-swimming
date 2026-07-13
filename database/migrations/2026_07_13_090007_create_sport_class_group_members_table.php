<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ordnet konkrete Sportklassen (z.B. "S4", "SB9", "SM14") einer
     * Sportklassengruppe zu — datengetrieben statt hartkodierter Mapping-
     * Tabelle im Code (siehe Projektprinzip "keine hardcoded Sportklassen").
     */
    public function up(): void
    {
        Schema::create('sport_class_group_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sport_class_group_id')->constrained()->cascadeOnDelete();
            $table->string('sport_class', 10); // z.B. "S4", "SB9", "SM14"

            $table->timestamps();

            $table->unique('sport_class');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sport_class_group_members');
    }
};

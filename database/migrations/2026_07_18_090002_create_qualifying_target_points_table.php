<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * qualifying_target_points — Zielpunkte je Sportklasse für eine
     * Richtzeitenliste (Phase 2 der Spec). Default 100, pro vollem
     * Sportklassen-Code (z.B. "S2", "SB2", "SM2") einzeln überschreibbar,
     * wie im Beispiel der Spezifikation (S2=110, SB2=120).
     *
     * sport_class wird bewusst als String geführt (kein FK auf
     * base_time_sport_classes), da diese Tabelle nur die reine Klassenzahl
     * ohne S/SB/SM-Präfix führt — der Präfix ergibt sich hier je nach
     * Sportart-Kategorie (S/SB/SM), siehe WorldAquaticsPointsService.
     */
    public function up(): void
    {
        Schema::create('qualifying_target_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qualifying_time_list_id')
                ->constrained()->cascadeOnDelete();

            $table->string('sport_class', 15); // z.B. "S2", "SB2", "SM2"
            $table->unsignedSmallInteger('points')->default(100);

            $table->timestamps();

            $table->unique(['qualifying_time_list_id', 'sport_class'], 'qtp_list_class_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualifying_target_points');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * qualifying_time_lists — Kopfdaten der Richtzeitenliste für ÖSTM & ÖM
     * (Anforderungsspezifikation "Richtzeiten ÖSTM & ÖM", Phase 1 + 3).
     *
     * Ein Datensatz pro Wettkampfjahr. Frühere Jahre werden nie überschrieben
     * (Phase 3 — Historisierung), sondern bleiben als eigener Datensatz bestehen.
     */
    public function up(): void
    {
        Schema::create('qualifying_time_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualifying_time_lists');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_time_sport_classes', function (Blueprint $table) {
            $table->id();

            // z.B. "S1" … "S21", sowie die kombinierten Staffelklassen "S20", "S34", "S49"
            // (in der Excel-Quelle als R20/R34/R49 bezeichnet, siehe RecordImportService/RelayClassValidator).
            $table->string('code', 10)->unique();

            // Anzeige-Reihenfolge, analog zur Spaltenreihenfolge in der Excel-Datei.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_time_sport_classes');
    }
};

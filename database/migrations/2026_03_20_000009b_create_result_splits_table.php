<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * result_splits — basierend auf LENEX 3.0 Element SPLIT
     *
     * Laut LENEX Spec: "split times are always saved continuously"
     * d.h. jede Split-Zeit ist die kumulierte Zeit ab Start, nicht die Teilzeit.
     *
     * SPLIT Attribute:
     *   distance → Distanz wo die Split-Zeit gemessen wurde (in Metern)
     *   swimtime → Kumulierte Zeit in Swim-Time-Format
     */
    public function up(): void
    {
        Schema::create('result_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_id')->constrained()->cascadeOnDelete();

            // Distanz in Metern an der die Split-Zeit gemessen wurde
            $table->integer('distance');

            // Kumulierte Zeit in Hundertstelsekunden
            $table->integer('split_time');

            $table->timestamps();

            // Pro Result nur eine Split-Zeit pro Distanz
            $table->unique(['result_id', 'distance']);
            $table->index('result_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_splits');
    }
};

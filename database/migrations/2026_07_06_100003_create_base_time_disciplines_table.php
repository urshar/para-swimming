<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_time_disciplines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stroke_type_id')->constrained('stroke_types');

            $table->unsignedSmallInteger('distance');
            $table->unsignedTinyInteger('relay_count')->default(1);

            // Excel-Kürzel des Bewerbs, z.B. "50FR", "4x100ME".
            $table->string('code', 20)->unique();

            $table->timestamps();

            $table->unique(
                ['stroke_type_id', 'distance', 'relay_count'],
                'base_time_disciplines_unique_def'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_time_disciplines');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_times', function (Blueprint $table) {
            $table->id();

            $table->foreignId('base_time_version_id')
                ->constrained('base_time_versions')
                ->cascadeOnDelete();

            $table->foreignId('base_time_category_id')
                ->constrained('base_time_categories')
                ->cascadeOnDelete();

            $table->foreignId('base_time_discipline_id')
                ->constrained('base_time_disciplines')
                ->cascadeOnDelete();

            $table->foreignId('base_time_sport_class_id')
                ->constrained('base_time_sport_classes')
                ->cascadeOnDelete();

            // Basiszeit in Hundertstelsekunden, analog zu TimeParser/results.swim_time.
            // 0 bedeutet "Bewerb existiert für diese Sportklasse nicht" (value_type = NOT_APPLICABLE).
            $table->unsignedInteger('value_centiseconds')->default(0);

            // MANUAL: manuell gepflegter Weltrekord (editierbar)
            // CALCULATED: automatisch aus base_time_derivation_rules hergeleitet (nicht editierbar)
            // NOT_APPLICABLE: Bewerb existiert nicht für diese Klasse (nicht editierbar)
            $table->string('value_type', 20)->default('MANUAL');

            $table->timestamps();

            $table->unique(
                ['base_time_version_id', 'base_time_category_id', 'base_time_discipline_id', 'base_time_sport_class_id'],
                'base_times_unique_cell'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_times');
    }
};

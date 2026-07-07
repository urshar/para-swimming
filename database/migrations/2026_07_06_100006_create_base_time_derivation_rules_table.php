<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_time_derivation_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('base_time_category_id')
                ->constrained('base_time_categories')
                ->cascadeOnDelete();

            // Kürzere/längere Distanz desselben Bewerbs-Paars, z.B. 100BK → 200BK.
            $table->foreignId('shorter_discipline_id')
                ->constrained('base_time_disciplines')
                ->cascadeOnDelete();

            $table->foreignId('longer_discipline_id')
                ->constrained('base_time_disciplines')
                ->cascadeOnDelete();

            // Override: der Durchschnitts-Wachstumsfaktor wird aus einer anderen Kategorie übernommen
            // (z.B. LC Mixed nutzt den Faktor von LC Men). Null = eigene Kategorie verwenden.
            $table->foreignId('ratio_reference_category_id')
                ->nullable()
                ->constrained('base_time_categories')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['base_time_category_id', 'shorter_discipline_id', 'longer_discipline_id'],
                'base_time_derivation_rules_unique_pair'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_time_derivation_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('base_time_derivation_rules', function (Blueprint $table) {
            // Manche Herleitungen nutzen nicht den Wachstumsfaktor des eigenen Bewerbs-Paars,
            // sondern den eines anderen (z.B. 1500FR nutzt den Faktor von "400FR to 800FR").
            // Null = eigenes Paar (shorter_discipline_id/longer_discipline_id) verwenden.
            $table->foreignId('ratio_shorter_discipline_id')
                ->nullable()
                ->after('ratio_reference_category_id')
                ->constrained('base_time_disciplines')
                ->nullOnDelete();

            $table->foreignId('ratio_longer_discipline_id')
                ->nullable()
                ->after('ratio_shorter_discipline_id')
                ->constrained('base_time_disciplines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('base_time_derivation_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ratio_shorter_discipline_id');
            $table->dropConstrainedForeignId('ratio_longer_discipline_id');
        });
    }
};

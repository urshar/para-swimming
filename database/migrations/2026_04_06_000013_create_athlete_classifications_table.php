<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classifications-History eines Athleten.
     *
     * Pro Klassifikationsereignis: 1 medizinischer + 2 technische Klassifizierer.
     * Klassifizierer können bei jeder Klassifikation unterschiedlich sein.
     */
    public function up(): void
    {
        Schema::create('athlete_classifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();

            // Die drei Klassifizierer des Ereignisses
            $table->foreignId('med_classifier_id')
                ->nullable()
                ->constrained('classifiers')
                ->nullOnDelete();

            $table->foreignId('tech1_classifier_id')
                ->nullable()
                ->constrained('classifiers')
                ->nullOnDelete();

            $table->foreignId('tech2_classifier_id')
                ->nullable()
                ->constrained('classifiers')
                ->nullOnDelete();

            // Wann und wo
            $table->date('classified_at');
            $table->string('location')->nullable(); // Stadt / Wettkampfstätte

            // Ergebnis / Sportklasse nach dieser Klassifikation
            $table->string('sport_class_result')->nullable(); // z.B. "S4", "SB3", "SM4"
            $table->enum('status', ['CONFIRMED', 'NEW', 'REVIEW', 'OBSERVATION'])->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('athlete_id');
            $table->index('classified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_classifications');
    }
};

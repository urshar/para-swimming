<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exceptions die bei einem Klassifikationsereignis vergeben wurden.
     *
     * Spiegelt die Struktur von athlete_exceptions, ist aber an eine
     * spezifische Klassifikation gebunden (nicht direkt an den Athleten).
     *
     * Unique-Constraint identisch zu athlete_exceptions:
     *   Pro Klassifikation + Code + Kategorie nur ein Eintrag.
     *
     * Beim Speichern werden diese Exceptions auch in athlete_exceptions
     * (Stammdaten des Athleten) übernommen.
     */
    public function up(): void
    {
        Schema::create('athlete_classification_exceptions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('athlete_classification_id');
            $table->foreign('athlete_classification_id', 'ace_classification_fk')
                ->references('id')
                ->on('athlete_classifications')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('exception_code_id');
            $table->foreign('exception_code_id', 'ace_exception_code_fk')
                ->references('id')
                ->on('exception_codes')
                ->cascadeOnDelete();

            // Für welche Sport-Kategorie gilt die Exception (S / SB / SM)
            // null = allgemein (nicht stilabhängig: H, Y, A, T, B …)
            $table->enum('category', ['S', 'SB', 'SM'])->nullable();

            // Optionale Notiz (z.B. Ablaufdatum, Bemerkung)
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // Pro Klassifikation + Code + Kategorie nur ein Eintrag —
            // gleiche Logik wie athlete_exception_unique
            $table->unique(
                ['athlete_classification_id', 'exception_code_id', 'category'],
                'aclassif_exc_unique'
            );

            $table->index('athlete_classification_id', 'ace_classification_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_classification_exceptions');
    }
};

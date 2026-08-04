<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ersetzt wps_scm_derivations durch wps_scm_conversion_factors.
     *
     * Hintergrund (Spec [S1]): Für Kurzbahn werden keine eigenen Gompertz-Parameter mehr
     * abgeleitet. Stattdessen wird die ZEIT auf ein Langbahn-Äquivalent umgerechnet und darauf
     * die offizielle Tabelle angewandt:
     *
     *     p_LCM = p_SCM × factor
     *
     * Mathematisch gleichwertig zu einer Skalierung von Parameter c, praktisch aber
     * erklärbar, überprüfbar und wartungsarm: es entsteht keine parallele Parametertabelle,
     * die bei jeder neuen offiziellen Veröffentlichung neu abgeleitet werden müsste.
     *
     * wps_scm_derivations wurde in Phase 1 angelegt, aber nie befüllt — das Ersetzen ist
     * daher gefahrlos.
     */
    public function up(): void
    {
        Schema::dropIfExists('wps_scm_derivations');

        Schema::create('wps_scm_conversion_factors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stroke_type_id')
                ->constrained('stroke_types')
                ->restrictOnDelete();

            // null = gilt für alle Strecken dieses Schwimmstils
            $table->smallInteger('distance')->unsigned()->nullable();

            // null = Sammelwert für alle Sportklassen
            $table->string('sport_class', 15)->nullable();

            // null = geschlechtsunabhängig
            $table->string('gender', 1)->nullable();

            // p_LCM = p_SCM × factor. Stets > 1, da auf der Kurzbahn schneller geschwommen
            // wird. Fünf Nachkommastellen: die Werte liegen typischerweise zwischen 1,01 und
            // 1,04, eine gröbere Auflösung verschiebt die Punkte spürbar.
            $table->decimal('factor', 8, 5);

            // own_data | literature | manual
            $table->string('source', 50);

            // Anzahl der Athleten hinter dem Faktor — nur bei own_data gesetzt
            $table->integer('sample_size')->nullable();

            // high | medium | low
            $table->string('confidence_level', 20)->default('low');

            $table->text('notes')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            // Benannter Index: der automatisch erzeugte Name überschritte die MySQL-Grenze.
            $table->unique(
                ['stroke_type_id', 'distance', 'sport_class', 'gender'],
                'wps_scm_factors_unique_combo'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wps_scm_conversion_factors');

        // wps_scm_derivations wird bewusst NICHT wiederhergestellt: die Tabelle war nie
        // befüllt, und ihr Konzept ist mit [S1] überholt.
    }
};

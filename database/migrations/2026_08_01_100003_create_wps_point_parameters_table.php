<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * wps_point_parameters — die Gompertz-Parameter der WPS-Punkteberechnung.
     *
     *   q = a * e^(-e^(b - c/p))     p = Zeit in Sekunden
     *
     * Merkmale sind bewusst direkt hinterlegt statt als Fremdschlüssel auf die
     * base_time_*-Dimensionstabellen (Entscheidung [E1] der Spec): jene gehören fachlich zum
     * World-Aquatics-Modul und führen Sportklassen ohne S/SB/SM-Differenzierung, die WPS
     * zwingend benötigt. Einzige wiederverwendete Dimension ist stroke_types — sie ist neutral
     * und wird auch von swim_events referenziert.
     *
     * sport_class wird im selben Format geführt wie results.sport_class (z.B. "S9", "SB8",
     * "SM10"), damit die Auflösung ohne Umkodierung erfolgen kann.
     *
     * Präzision: die offizielle Quelle führt b mit 6 und c mit 3 Nachkommastellen —
     * decimal(14,6) ist damit verlustfrei. Eine spätere Erhöhung würde alle bestehenden
     * Berechnungen unmerklich verschieben und ist daher vor dem Erstimport zu klären.
     */
    public function up(): void
    {
        Schema::create('wps_point_parameters', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wps_point_version_id')
                ->constrained('wps_point_versions')
                ->cascadeOnDelete();

            // LCM oder SCM. Kein DB-Enum, analog zu base_time_categories.course —
            // Validierung erfolgt im Form Request bzw. Importservice.
            $table->string('course', 3);

            // M oder F. Staffel-Geschlechter (X/A) sind nicht vorgesehen, siehe relay_count.
            $table->string('gender', 1);

            $table->foreignId('stroke_type_id')
                ->constrained('stroke_types')
                ->restrictOnDelete();

            $table->smallInteger('distance')->unsigned();

            // WPS veröffentlicht derzeit keine Staffelparameter. Die Spalte ist vorhanden,
            // damit spätere Veröffentlichungen ohne Migration importierbar sind.
            $table->tinyInteger('relay_count')->unsigned()->default(1);

            $table->string('sport_class', 15);

            $table->decimal('parameter_a', 14, 6);
            $table->decimal('parameter_b', 14, 6);
            $table->decimal('parameter_c', 14, 6);

            // false = aus LCM-Daten abgeleitet (SCM), true = offiziell veröffentlicht.
            // Bestimmt den Berechnungstyp am Ergebnis (official/estimated).
            $table->boolean('official')->default(true);

            $table->string('source')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Benannte Indizes: der automatisch erzeugte Name überschritte die MySQL-Grenze
            // von 64 Zeichen.
            $table->unique([
                'wps_point_version_id',
                'course',
                'gender',
                'stroke_type_id',
                'distance',
                'relay_count',
                'sport_class',
            ], 'wps_params_unique_combo');

            // Suchindex in der Reihenfolge, in der der Calculator auflöst.
            $table->index([
                'wps_point_version_id',
                'course',
                'gender',
                'sport_class',
            ], 'wps_params_lookup');
        });
    }

    public function down(): void
    {
        // Kein separates dropForeign/dropUnique nötig: dropIfExists entfernt die Tabelle samt
        // Indizes und Fremdschlüsseln. Die Reihenfolge-Regel (dropForeign vor dropUnique, sonst
        // MySQL-Fehler 1553) greift nur beim Ändern einer bestehenden Tabelle.
        Schema::dropIfExists('wps_point_parameters');
    }
};

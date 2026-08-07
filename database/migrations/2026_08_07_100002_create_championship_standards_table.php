<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * championship_standards — die einzelne Norm je Bewerb (Stroke + Distanz),
     * Geschlecht und Sportklasse innerhalb einer Meisterschaft
     * (Spec "WPS Qualification" §5.2).
     *
     * Bewusst KEIN FK auf swim_events, da SwimEvent pro Meet neu angelegt wird —
     * Normen gelten meetübergreifend für einen Bewerbstyp. Struktur analog
     * qualifying_times (stroke_type_id + distance).
     *
     * Zwei Normebenen werden BEIDE gespeichert (Entscheidung [Q2]):
     *   - mqs_centiseconds / met_centiseconds — die WPS-Normen
     *   - obsv_percent / obsv_centiseconds    — die ggf. schärfere ÖBSV-Norm
     * wird nur die schärfere gespeichert, lässt sich später nicht mehr
     * nachvollziehen, an welcher Hürde jemand gescheitert ist.
     *
     * mqs_centiseconds ist nullable, damit eine Zeile auch dann existieren kann,
     * wenn nur eine MET veröffentlicht wurde.
     *
     * obsv_percent unterscheidet null von 0 (Entscheidung [Q3]):
     *   null = noch nicht festgelegt (offene Zeile)
     *   0    = bewusst die MQS übernommen
     * deshalb nullable ohne Default — ein Default würde die Unterscheidung tilgen.
     *
     * Zeiten in Hundertstelsekunden, wie überall im Projekt (results.swim_time).
     */
    public function up(): void
    {
        Schema::create('championship_standards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('championship_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('stroke_type_id')
                ->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('distance');
            $table->enum('gender', ['M', 'F']);
            $table->string('sport_class', 15); // z.B. "S7", "SB4", "SM3"

            $table->integer('mqs_centiseconds')->nullable();
            $table->integer('met_centiseconds')->nullable();

            $table->decimal('obsv_percent', 5)->nullable();
            $table->integer('obsv_centiseconds')->nullable();
            $table->boolean('obsv_is_manual')->default(false);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(
                ['championship_id', 'stroke_type_id', 'distance', 'gender', 'sport_class'],
                'championship_standards_unique_combo'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_standards');
    }
};

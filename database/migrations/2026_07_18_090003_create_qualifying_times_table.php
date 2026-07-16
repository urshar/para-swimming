<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * qualifying_times — die einzelne Richtzeit je Bewerb (Stroke + Distanz +
     * Geschlecht) und Sportklasse innerhalb einer Richtzeitenliste
     * (Phase 1 + 2 der Spec "Richtzeiten ÖSTM & ÖM").
     *
     * Bewusst KEIN FK auf swim_events, da SwimEvent pro Meet neu angelegt
     * wird — Richtzeiten gelten meetübergreifend für einen Bewerbstyp.
     * Struktur analog base_time_disciplines (stroke_type_id + distance),
     * relay_count entfällt, da Staffeln laut Spec ausdrücklich nicht
     * berücksichtigt werden.
     *
     * value_centiseconds ist nullable, da in Phase 1 nur die Verwaltungs-, Struktur entsteht — die automatische Berechnung folgt in Phase 2.
     */
    public function up(): void
    {
        Schema::create('qualifying_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qualifying_time_list_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('stroke_type_id')
                ->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('distance');
            $table->enum('gender', ['M', 'F']);
            $table->string('sport_class', 15); // z.B. "S2", "SB2", "SM2"

            $table->integer('value_centiseconds')->nullable();

            $table->timestamps();

            $table->unique(
                ['qualifying_time_list_id', 'stroke_type_id', 'distance', 'gender', 'sport_class'],
                'qt_list_event_class_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualifying_times');
    }
};

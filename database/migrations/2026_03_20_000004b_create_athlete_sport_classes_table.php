<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('athlete_sport_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();

            // LENEX Kategorie-Mapping:
            //   S  → LENEX free   (Freistil, Rücken, Schmetterling)
            //   SB → LENEX breast (Brustschwimmen)
            //   SM → LENEX medley (Lagen)
            $table->enum('category', ['S', 'SB', 'SM']);

            // Klassennummer als String — erlaubt: 0-15, GER.AB, GER.GB
            $table->string('class_number', 10);

            // Vollständiger Display-String: "S4", "SB3", "SM14", "GER.AB"
            $table->string('sport_class', 15);

            // LENEX 3.0 *status Attribute (freestatus / breaststatus / medleystatus)
            $table->enum('status', [
                'NATIONAL',     // Nur national gültig
                'NEW',          // Noch nicht gültig
                'REVIEW',       // Muss dieses Jahr überprüft werden, bis Jahresende gültig
                'OBSERVATION',  // Muss beim Wettkampf beobachtet werden
                'CONFIRMED',    // Bestätigt für internationale Wettkämpfe
            ])->nullable();

            $table->timestamps();

            // Pro Athlet nur eine Klasse pro Kategorie
            $table->unique(['athlete_id', 'category']);
            $table->index('athlete_id');
            $table->index('sport_class');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('athlete_sport_classes');
    }
};

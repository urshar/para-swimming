<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wertungsgruppen je Bewerb (LENEX AGEGROUP).
 *
 * Eine Wertungsgruppe legt fest, welche Ergebnisse eines Bewerbs gemeinsam gewertet werden: Geschlecht (M/F/X oder
 * A = alle), Sportklassen als Nummern (bei Staffeln die Staffelklasse 14/20/34/49), optional Alter von–bis. Die
 * Kategorie (S/SB/SM) ergibt sich aus der Lage des Bewerbs. Die Zuordnung der Ergebnisse wird berechnet, nicht
 * gespeichert; ein Ergebnis kann in mehreren Gruppen gewertet werden.
 *
 * title: Meisterschaftstitel der Gruppe (OSTM = Österr. Staatsmeisterschaft, OM = Österr. Meisterschaft, null = ohne).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoring_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('swim_event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('gender', 1)->default('A');            // M / F / X / A
            $table->string('sport_classes', 100)->nullable();     // "1,2,3,4,5,6,7,8" — leer = alle Klassen
            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->string('title', 10)->nullable();             // OSTM / OM
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('lenex_agegroup_id')->nullable();
            $table->timestamps();

            $table->index(['swim_event_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoring_groups');
    }
};

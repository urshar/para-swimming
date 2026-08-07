<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * championships — internationale Meisterschaft (EM, WM, Paralympics) mit
     * Qualifikationszeitraum (Spec "WPS Qualification" §5.1).
     *
     * Eigene Tabelle statt Erweiterung von qualifying_time_lists: Die ÖBSV-Richtzeiten
     * und die Meisterschaftsnormen teilen zwar die Merkmale, haben aber anderen
     * Herausgeber, anderen Zweck und andere Lebensdauer (Entscheidung [Q1]).
     *
     * course ist vorhanden, obwohl die Normen bislang stets Langbahn sind — eine
     * Kurzbahn-Meisterschaft ist denkbar, und ohne das Feld wäre die Umrechnung
     * dann still falsch (§5.1).
     *
     * Meisterschaften werden nicht überschrieben; jede Ausgabe ist ein eigener
     * Datensatz (§9.3 Historisierung).
     */
    public function up(): void
    {
        Schema::create('championships', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('short_name', 50)->nullable();
            $table->string('type', 20); // EC / WC / PARALYMPICS / OTHER
            $table->unsignedSmallInteger('year');
            $table->string('course', 3)->default('LCM');

            $table->date('qualification_start');
            $table->date('qualification_end');

            $table->string('source', 255)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['year', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championships');
    }
};

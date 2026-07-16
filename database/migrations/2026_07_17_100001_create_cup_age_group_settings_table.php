<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aktivierung/Deaktivierung einzelner Altersgruppen pro Cup-Jahr (analog zu
     * cup_group_settings für Sportklassengruppen, Phase 1). Erik: soll generisch
     * über die Altersgruppen laufen (nicht nur "Jugendwertung" fest verdrahtet),
     * damit später z.B. eine Seniorenwertung genauso ergänzt werden kann.
     *
     * Ist eine Altersgruppe für einen Cup deaktiviert, werden die betroffenen
     * Athleten in der Gesamtwertung NICHT ausgeschlossen, sondern landen in
     * einer gemeinsamen, altersgruppen-übergreifenden Wertung (age_group_id
     * = null) — siehe GroupResolverService::resolveAgeGroup().
     *
     * Fehlt ein Eintrag für eine Kombination, gilt die Altersgruppe als aktiv
     * (Default), analog zu Cup::isGroupActive().
     */
    public function up(): void
    {
        Schema::create('cup_age_group_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('age_group_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['cup_id', 'age_group_id'], 'cup_age_group_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cup_age_group_settings');
    }
};

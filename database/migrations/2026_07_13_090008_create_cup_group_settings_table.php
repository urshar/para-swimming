<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aktivierung/Deaktivierung einzelner Sportklassengruppen pro Cup-Jahr
     * (Punkt 1 der Spec). Die globale Gruppendefinition (sport_class_groups)
     * bleibt davon unberührt — nur die Teilnahme am jeweiligen Cup-Jahr wird
     * hier gesteuert. Fehlt ein Eintrag für eine Kombination, gilt die
     * Gruppe für diesen Cup als aktiv (Default), siehe Cup::isGroupActive().
     */
    public function up(): void
    {
        Schema::create('cup_group_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sport_class_group_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['cup_id', 'sport_class_group_id'], 'cup_group_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cup_group_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * documents — polymorphe Dokumententabelle für den öffentlichen Bereich
     * (Spec public-frontend §4.1).
     *
     * Eine Tabelle für zwei Fälle: Veranstaltungsdokumente (documentable = Meet) und
     * sprachneutrale bzw. -spezifische Regelmente/Formulare ohne Bezug (documentable = null).
     * Getrennte Tabellen hätten dieselbe Anzeige- und Sprachauflösungslogik doppelt gebraucht.
     *
     * is_public und published_at sind zwei getrennte Schalter mit unterschiedlichem Zweck:
     * is_public entscheidet, ob ein Dokument überhaupt für eine öffentliche Auslieferung in
     * Frage kommt (siehe §4.3 zu LENEX-Dateien mit Meldungen); published_at, ob es *aktuell*
     * sichtbar ist. Beide default auf "nicht sichtbar" — ein Upload ist zunächst Entwurf.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            $table->nullableMorphs('documentable');

            $table->enum('category', ['INVITATION', 'START_LIST', 'RESULTS', 'REGULATION', 'FORM']);
            $table->string('title');

            // null = sprachneutrale Fassung, wird in beiden Sprachen gezeigt (§4.1).
            $table->string('locale', 5)->nullable();

            $table->string('path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->boolean('is_public')->default(false);
            $table->dateTime('published_at')->nullable();

            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index(['category', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};

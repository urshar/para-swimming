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

            // Gültigkeitsbereich:
            //   INTL = international gültig (Athlet hat SDMS/IPC-ID)
            //   NAT  = nur national gültig (ÖBSV)
            $table->enum('classification_scope', ['INTL', 'NAT'])->default('INTL');

            // Klassifikationsstatus — entspricht LENEX 3.0 *status Attributen,
            // erweitert um FRD und NE:
            //   NEW       = Erstklassifizierung, noch nicht bestätigt
            //   CONFIRMED = Bestätigt (international: LENEX CONFIRMED)
            //   REVIEW    = Muss überprüft werden (LENEX REVIEW / OBSERVATION)
            //   FRD       = Fixed Review Date → frd_year gibt das Jahr an
            //   NE        = Not Eligible (nicht klassifizierbar)
            $table->enum('classification_status', [
                'NEW',
                'CONFIRMED',
                'REVIEW',
                'FRD',
                'NE',
            ])->nullable();

            // Nur bei classification_status = FRD: Jahr des nächsten Review-Termins
            $table->smallInteger('frd_year')->nullable()->unsigned();

            $table->timestamps();

            // Pro Athlet nur eine Klasse pro Kategorie
            $table->unique(['athlete_id', 'category']);
            $table->index('athlete_id');
            $table->index('sport_class');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_sport_classes');
    }
};

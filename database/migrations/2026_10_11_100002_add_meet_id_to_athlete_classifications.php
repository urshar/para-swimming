<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klassen-Hinweise aus Ergebnislisten: Weicht beim LENEX-Import die Klasse eines Athleten in der Datei von seinen
 * Stammdaten ab, landet sie als Eintrag in seiner Klassifizierungshistorie — verknüpft mit dem Wettkampf, damit sie
 * beim Löschen des Wettkampfs mit verschwindet und ein erneuter Import nichts doppelt anlegt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athlete_classifications', function (Blueprint $table) {
            $table->foreignId('meet_id')->nullable()->after('athlete_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('athlete_classifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meet_id');
        });
    }
};

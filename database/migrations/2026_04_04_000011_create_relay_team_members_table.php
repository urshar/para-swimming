<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Verein beim Rekord + Staffelteam
 *
 * swim_records.club_id
 *   - Verein zum Zeitpunkt des Rekords (kann vom aktuellen Vereins des Athleten abweichen)
 *   - Bei Staffeln: der Staffelverein
 *   - nullable, da historische Datensätze keinen Verein haben können
 *
 * relay_team_members
 *   - Staffelmitglieder die den Rekord aufgestellt haben
 *   - LENEX: RELAY > RELAYPOSITIONS > RELAYPOSITION > ATHLETE
 *   - Athlet-Verknüpfung optional (manche Athleten sind nicht in der DB)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Staffelteam-Mitglieder
        Schema::create('relay_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('swim_record_id')->constrained('swim_records')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');          // RELAYPOSITION number="1"
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('birth_date')->nullable();
            $table->char('gender', 1)->nullable();            // M / F
            $table->foreignId('athlete_id')                   // optional: Verknüpfung mit Athleten-DB
                ->nullable()
                ->constrained('athletes')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['swim_record_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relay_team_members');
    }
};

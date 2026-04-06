<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('athletes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id')->constrained()->restrictOnDelete();

            // Aktiver Verein — wird via AthleteClubHistory verwaltet.
            // Dieses Feld zeigt immer den aktuellen Verein (Convenience-Feld).
            // Bei Vereinswechsel: club_history anlegen UND dieses Feld updaten.
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('name_prefix', 50)->nullable(); // "van den" etc.

            $table->date('birth_date')->nullable();

            // LENEX 3.0: M, F, N (non binary)
            $table->enum('gender', ['M', 'F', 'N'])->default('M');

            // Lizenznummern
            $table->string('license')->nullable();          // Nationale Lizenznummer
            $table->string('license_ipc')->nullable();      // SDMS ID von World Para Swimming
            $table->string('swrid')->nullable();            // swimrankings.net Global-ID

            // LENEX 3.0 ATHLETE.status
            $table->enum('status', ['EXHIBITION', 'FOREIGNER', 'ROOKIE'])->nullable();

            // Para-spezifisch
            $table->string('disability_type', 30)->nullable(); // physical, visual, intellectual

            // ── Neue Felder ───────────────────────────────────────────────────

            // Aktiv-Status
            $table->boolean('is_active')->default(true);

            // Internes Notizfeld
            $table->text('notes')->nullable();

            // Kontakt
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();

            // Adresse
            $table->string('address_street')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_zip', 20)->nullable();
            $table->string('address_country', 3)->nullable(); // ISO 3166-1 alpha-3

            // Level (ÖBSV-Einstufung) — aktueller Wert als Convenience-Feld.
            // Vollständige History → athlete_level_history Tabelle.
            $table->string('level', 50)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
            $table->index('nation_id');
            $table->index('club_id');
            $table->index('license');
            $table->index('license_ipc');
            $table->index('swrid');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athletes');
    }
};

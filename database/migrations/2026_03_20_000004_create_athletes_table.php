<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('athletes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('nation_id')->constrained()->restrictOnDelete();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('name_prefix', 50)->nullable(); // "van den" etc.

            $table->date('birth_date')->nullable();

            // LENEX 3.0: M, F, N (nonbinary)
            $table->enum('gender', ['M', 'F', 'N'])->default('M');

            // Lizenznummern
            $table->string('license')->nullable();          // Nationale Lizenznummer
            $table->string('license_ipc')->nullable();      // SDMS ID von World Para Swimming (license_ipc in LENEX)

            // LENEX 3.0 ATHLETE.status
            $table->enum('status', ['EXHIBITION', 'FOREIGNER', 'ROOKIE'])->nullable();

            // Para-spezifisch
            // Sport-Klassen → athlete_sport_classes Tabelle
            // disability_type ist nicht in LENEX, aber für interne Verwaltung nützlich
            $table->string('disability_type', 30)->nullable();  // physical, visual, intellectual

            // Global unique ID von swimrankings.net (swrid in LENEX)
            $table->string('swrid')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
            $table->index('nation_id');
            $table->index('club_id');
            $table->index('license');
            $table->index('license_ipc');
            $table->index('swrid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('athletes');
    }
};

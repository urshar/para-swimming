<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Starts von Vereinen außerhalb Österreichs (Gäste) — nur für die Statistik und die vollständige Ergebnisliste. Bewusst
 * ohne Bezug zu athletes und clubs: Ausländische Schwimmer und Vereine sollen die Stammdaten nicht aufblähen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foreign_starts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('swim_event_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_relay')->default(false);

            // Verein laut Datei
            $table->string('club_name');
            $table->string('club_code', 20)->nullable();
            $table->foreignId('nation_id')->nullable()->constrained()->nullOnDelete();

            // Schwimmer (leer bei Staffeln); person_key zählt Teilnehmer über mehrere Starts
            $table->string('person_key')->nullable();
            $table->string('last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->date('birth_date')->nullable();

            // Bei Staffeln die Wertung der Staffel (M/F/X)
            $table->string('gender', 1)->nullable();
            $table->string('sport_class', 15)->nullable();

            $table->unsignedInteger('swim_time')->nullable();
            $table->string('status', 10)->nullable();
            $table->unsignedSmallInteger('place')->nullable();
            $table->string('lenex_result_id')->nullable();

            $table->timestamps();

            $table->index(['meet_id', 'swim_event_id']);
            $table->index('nation_id');
            $table->index('person_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foreign_starts');
    }
};

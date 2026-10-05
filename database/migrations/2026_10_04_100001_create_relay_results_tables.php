<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staffelergebnisse (eigene Tabellen, analog zu relay_entries / relay_entry_members).
 *
 * relay_results
 *   - Ein Ergebnis je Staffel und Bewerb. gender ist das Staffel-Geschlecht der Mannschaft (M/F/X),
 *     nicht das des Bewerbs: LENEX RELAY gender, bei manueller Erfassung aus den Mitgliedern abgeleitet.
 *   - relay_class: Wertungsklasse (S14, S20, S34, S49 ...), aus LENEX AGEGROUP handicap bzw. RelayClassValidator.
 *
 * relay_result_members
 *   - Eingesetzte Schwimmer je Position. athlete_id optional (unbekannter Athlet im Import); Name als Kopie.
 *
 * relay_result_splits
 *   - Zwischenzeiten der Staffel.
 *
 * swim_records.relay_result_id
 *   - Herkunft eines Staffelrekords (Gegenstück zu result_id bei Einzelrekorden).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relay_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('swim_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('relay_number')->nullable();   // LENEX RELAY number (Mannschaft 1, 2 ...)
            $table->string('name', 100)->nullable();
            $table->char('gender', 1);                                 // M / F / X
            $table->string('relay_class', 10)->nullable();
            $table->integer('swim_time')->nullable();                  // Hundertstelsekunden
            $table->string('status', 10)->nullable();                  // EXH, DSQ, DNS, DNF, SICK, WDR
            $table->integer('place')->nullable();
            $table->integer('points')->nullable();
            $table->integer('heat')->nullable();
            $table->integer('lane')->nullable();
            $table->string('comment')->nullable();
            $table->boolean('is_world_record')->default(false);
            $table->boolean('is_european_record')->default(false);
            $table->boolean('is_national_record')->default(false);
            $table->boolean('is_junior_record')->default(false);
            $table->boolean('is_regional_record')->default(false);
            $table->boolean('is_regional_junior_record')->default(false);
            $table->string('lenex_result_id')->nullable();
            $table->timestamps();

            $table->index(['meet_id', 'swim_event_id']);
        });

        Schema::create('relay_result_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relay_result_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('athlete_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->char('gender', 1)->nullable();
            $table->string('sport_class', 10)->nullable();
            $table->integer('reaction_time')->nullable();
            $table->timestamps();

            $table->unique(['relay_result_id', 'position']);
        });

        Schema::create('relay_result_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relay_result_id')->constrained()->cascadeOnDelete();
            $table->integer('distance');
            $table->integer('split_time');
            $table->timestamps();
        });

        Schema::table('swim_records', function (Blueprint $table) {
            $table->foreignId('relay_result_id')->nullable()->after('result_id')
                ->constrained('relay_results')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('swim_records', function (Blueprint $table) {
            $table->dropForeign(['relay_result_id']);
            $table->dropColumn('relay_result_id');
        });
        Schema::dropIfExists('relay_result_splits');
        Schema::dropIfExists('relay_result_members');
        Schema::dropIfExists('relay_results');
    }
};

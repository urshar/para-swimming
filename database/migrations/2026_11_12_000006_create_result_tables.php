<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einzel- und Staffelergebnisse, Zwischenzeiten, ausländische Starts und Leistungsnotizen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->foreignId('swim_event_id')->constrained('swim_events')->cascadeOnDelete();
            $table->foreignId('athlete_id')->index()->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('club_id')->index()->constrained('clubs')->restrictOnDelete();
            // Schwimmzeit in Hundertstelsekunden (null = keine Zeit, DNS/DNF/DSQ)
            $table->integer('swim_time')->nullable();
            // LENEX RESULT.status
            $table->enum('status', ['EXH', 'DSQ', 'DNS', 'DNF', 'SICK', 'WDR'])->nullable()->index();
            // LENEX RESULT.handicap — Sport-Klasse für dieses Ergebnis
            $table->string('sport_class', 15)->nullable()->index();
            // Para-Punkte (LENEX RESULT.points)
            $table->integer('points')->nullable();
            // Achtung: nicht auf 1000 begrenzt. Der Gompertz-Parameter a beträgt in der
            // Version 2026 durchgängig 1200 — WPS-Punkte können über 1000 liegen.
            $table->integer('wps_points')->nullable()->index();
            $table->foreignId('wps_point_version_id')->nullable()->constrained('wps_point_versions')->nullOnDelete();
            // Der konkret verwendete Parametersatz — macht die Berechnung reproduzierbar.
            $table->foreignId('wps_point_parameter_id')->nullable()->constrained('wps_point_parameters')->nullOnDelete();
            // official = offiziell veröffentlichte Parameter (LCM)
            // estimated = aus LCM abgeleitete Parameter (SCM), nicht von WPS anerkannt
            $table->string('wps_calculation_type', 10)->nullable();
            // Hundertstelsekunden, wie results.swim_time. Nur bei umgerechneten Zeiten gesetzt.
            $table->integer('wps_estimated_lcm_time')->nullable();
            $table->foreignId('wps_conversion_factor_id')->nullable()->constrained('wps_scm_conversion_factors')->nullOnDelete();
            $table->timestamp('wps_calculated_at')->nullable();
            // Lauf und Bahn
            $table->integer('heat')->nullable();
            $table->integer('lane')->nullable();
            // Platzierung
            $table->integer('place')->nullable();
            // LENEX RESULT.reactiontime (Hundertstelsekunden, kann negativ sein → Fehlstart)
            $table->integer('reaction_time')->nullable();
            // LENEX RESULT.comment (z.B. DSQ-Begründung, "Ratification pending")
            $table->string('comment')->nullable();
            // Rekord-Flags (aus LENEX Import, werden auch vom RecordCheckerService gesetzt)
            $table->boolean('is_world_record')->default(false);
            $table->boolean('is_european_record')->default(false);
            $table->boolean('is_national_record')->default(false);
            $table->boolean('is_junior_record')->default(false);
            $table->boolean('is_regional_record')->default(false);
            $table->boolean('is_regional_junior_record')->default(false);
            // LENEX resultid — nicht unique in unserer DB (verschiedene Meets)
            // Nur für Import-Referenz innerhalb eines Meets
            $table->string('lenex_result_id')->nullable();
            $table->timestamps();

            $table->unique(['meet_id', 'swim_event_id', 'athlete_id', 'heat', 'lane']);
            $table->index(['swim_event_id', 'swim_time']);
        });

        Schema::create('result_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_id')->index()->constrained('results')->cascadeOnDelete();
            // Distanz in Metern an der die Split-Zeit gemessen wurde
            $table->integer('distance');
            // Kumulierte Zeit in Hundertstelsekunden
            $table->integer('split_time');
            $table->timestamps();

            $table->unique(['result_id', 'distance']);
        });

        Schema::create('relay_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->foreignId('swim_event_id')->constrained('swim_events')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->restrictOnDelete();
            // LENEX RELAY number (Mannschaft 1, 2 ...)
            $table->unsignedTinyInteger('relay_number')->nullable();
            $table->string('name', 100)->nullable();
            // M / F / X
            $table->char('gender', 1);
            $table->string('relay_class', 10)->nullable();
            // Hundertstelsekunden
            $table->integer('swim_time')->nullable();
            // EXH, DSQ, DNS, DNF, SICK, WDR
            $table->string('status', 10)->nullable();
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
            $table->foreignId('relay_result_id')->constrained('relay_results')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('athlete_id')->nullable()->constrained('athletes')->nullOnDelete();
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
            $table->foreignId('relay_result_id')->constrained('relay_results')->cascadeOnDelete();
            $table->integer('distance');
            $table->integer('split_time');
            $table->timestamps();
        });

        Schema::create('foreign_starts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->foreignId('swim_event_id')->constrained('swim_events')->cascadeOnDelete();
            $table->boolean('is_relay')->default(false);
            // Verein laut Datei
            $table->string('club_name');
            $table->string('club_code', 20)->nullable();
            $table->foreignId('nation_id')->nullable()->index()->constrained('nations')->nullOnDelete();
            // Schwimmer (leer bei Staffeln); person_key zählt Teilnehmer über mehrere Starts
            $table->string('person_key')->nullable()->index();
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
        });

        Schema::create('athlete_performance_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();
            // Wird das Ergebnis gelöscht, bleibt die Notiz mit ihrem Datum bestehen: Die
            // Beobachtung über den Athleten gilt weiter, auch wenn der Start entfällt.
            $table->foreignId('result_id')->nullable()->constrained('results')->nullOnDelete();
            $table->date('noted_on');
            $table->string('category', 30);
            $table->text('note');
            // Wer die Notiz verfasst hat — bei einer Einschätzung gehört die Herkunft dazu.
            // nullOnDelete, damit das Löschen eines Kontos keine Notizen mitreißt.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['athlete_id', 'noted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_performance_notes');
        Schema::dropIfExists('foreign_starts');
        Schema::dropIfExists('relay_result_splits');
        Schema::dropIfExists('relay_result_members');
        Schema::dropIfExists('relay_results');
        Schema::dropIfExists('result_splits');
        Schema::dropIfExists('results');
    }
};

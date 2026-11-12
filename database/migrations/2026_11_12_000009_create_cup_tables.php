<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cup-Einstellungen und Cup-Wertung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cup_age_group_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cup_id')->constrained('cups')->cascadeOnDelete();
            $table->foreignId('sport_class_group_id')->constrained('sport_class_groups')->cascadeOnDelete();
            $table->foreignId('age_group_id')->constrained('age_groups')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['cup_id', 'sport_class_group_id', 'age_group_id'], 'cup_age_group_unique');
        });

        Schema::create('cup_group_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cup_id')->constrained('cups')->cascadeOnDelete();
            $table->foreignId('sport_class_group_id')->constrained('sport_class_groups')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('gender_combined')->default(false);
            $table->timestamps();

            $table->unique(['cup_id', 'sport_class_group_id'], 'cup_group_unique');
        });

        Schema::create('cup_daily_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cup_id')->constrained('cups')->cascadeOnDelete();
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->restrictOnDelete();
            // Das konkrete Result, das als bestes Ergebnis des Tages gewertet wurde.
            $table->foreignId('result_id')->constrained('results')->cascadeOnDelete();
            $table->foreignId('sport_class_group_id')->constrained('sport_class_groups')->restrictOnDelete();
            $table->enum('gender', ['M', 'F']);
            $table->unsignedInteger('points');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->index(['meet_id', 'gender', 'sport_class_group_id']);
            $table->unique(['cup_id', 'meet_id', 'athlete_id'], 'cup_daily_results_unique');
        });

        Schema::create('cup_overall_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cup_id')->constrained('cups')->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->restrictOnDelete();
            $table->foreignId('sport_class_group_id')->constrained('sport_class_groups')->restrictOnDelete();
            $table->enum('gender', ['M', 'F']);
            // nullable — falls kein Geburtsdatum hinterlegt ist, siehe GroupResolverService::resolveAgeGroup()
            $table->foreignId('age_group_id')->nullable()->constrained('age_groups')->nullOnDelete();
            $table->unsignedInteger('total_points');
            // wie viele Tageswertungen tatsächlich eingeflossen sind
            $table->unsignedTinyInteger('rounds_counted');
            $table->json('counted_meet_ids');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->index(['cup_id', 'gender', 'sport_class_group_id', 'age_group_id'], 'cup_overall_results_bracket_idx');
            $table->unique(['cup_id', 'athlete_id', 'gender', 'sport_class_group_id'], 'cup_overall_results_unique');
        });

        Schema::create('cup_top_group_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cup_id')->constrained('cups')->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->boolean('is_top_group');
            $table->boolean('calculated_is_top_group')->default(false);
            // null = nicht in der Top-Gruppe
            $table->string('reason', 20)->nullable();
            $table->boolean('previous_is_top_group')->nullable();
            $table->unsignedSmallInteger('last_start_year')->nullable();
            $table->unsignedInteger('last_start_points')->nullable();
            $table->unsignedSmallInteger('prior_start_year')->nullable();
            $table->unsignedInteger('prior_start_points')->nullable();
            $table->boolean('manual_is_top_group')->nullable();
            $table->text('manual_note')->nullable();
            $table->foreignId('manual_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manual_set_at')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['cup_id', 'athlete_id'], 'cup_top_group_class_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cup_top_group_classifications');
        Schema::dropIfExists('cup_overall_results');
        Schema::dropIfExists('cup_daily_results');
        Schema::dropIfExists('cup_group_settings');
        Schema::dropIfExists('cup_age_group_settings');
    }
};

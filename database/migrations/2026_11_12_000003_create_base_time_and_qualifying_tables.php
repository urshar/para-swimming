<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Basiszeiten, Richtzeiten-Listen und Meisterschafts-Normen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_time_categories', function (Blueprint $table) {
            $table->id();
            // Interner Code, z.B. "LC_MEN", "SC_MIXED" — entspricht einem Excel-Arbeitsblatt.
            $table->string('code', 30)->unique();
            // Kurs (LCM/SCM) und Geschlecht (M/F/X) — Werte werden per Form Request validiert,
            // analog zum bestehenden Muster bei Meet/Result (course-Spalte ohne DB-Enum).
            $table->string('course', 3);
            $table->string('gender', 1);
            $table->string('label', 100);
            // Manche Kategorien (z.B. Mixed-Staffeln) übernehmen die Durchschnitts-Wachstumsfaktoren
            // einer anderen Kategorie (z.B. LC Mixed → LC Men), siehe base_time_derivation_rules.
            $table->foreignId('ratio_reference_category_id')->nullable()->constrained('base_time_categories')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('base_time_disciplines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stroke_type_id')->constrained('stroke_types');
            $table->unsignedSmallInteger('distance');
            $table->unsignedTinyInteger('relay_count')->default(1);
            // Excel-Kürzel des Bewerbs, z.B. "50FR", "4x100ME".
            $table->string('code', 20)->unique();
            $table->timestamps();

            $table->unique(['stroke_type_id', 'distance', 'relay_count'], 'base_time_disciplines_unique_def');
        });

        Schema::create('base_time_sport_classes', function (Blueprint $table) {
            $table->id();
            // z.B. "S1" … "S21", sowie die kombinierten Staffelklassen "S20", "S34", "S49"
            // (in der Excel-Quelle als R20/R34/R49 bezeichnet, siehe RecordImportService/RelayClassValidator).
            $table->string('code', 10)->unique();
            // Anzeige-Reihenfolge, analog zur Spaltenreihenfolge in der Excel-Datei.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('base_time_versions', function (Blueprint $table) {
            $table->id();
            // Bezeichnung der Version, z.B. "2021-2026", "2027 Update"
            $table->string('label', 100);
            // Gültigkeitszeitraum. valid_until = null bedeutet "bis auf Weiteres gültig".
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();
        });

        Schema::create('base_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_time_version_id')->constrained('base_time_versions')->cascadeOnDelete();
            $table->foreignId('base_time_category_id')->constrained('base_time_categories')->cascadeOnDelete();
            $table->foreignId('base_time_discipline_id')->constrained('base_time_disciplines')->cascadeOnDelete();
            $table->foreignId('base_time_sport_class_id')->constrained('base_time_sport_classes')->cascadeOnDelete();
            // Basiszeit in Hundertstelsekunden, analog zu TimeParser/results.swim_time.
            // 0 bedeutet "Bewerb existiert für diese Sportklasse nicht" (value_type = NOT_APPLICABLE).
            $table->unsignedInteger('value_centiseconds')->default(0);
            // MANUAL: manuell gepflegter Weltrekord (editierbar)
            // CALCULATED: automatisch aus base_time_derivation_rules hergeleitet (nicht editierbar)
            // NOT_APPLICABLE: Bewerb existiert nicht für diese Klasse (nicht editierbar)
            $table->string('value_type', 20)->default('MANUAL');
            $table->timestamps();

            $table->unique(['base_time_version_id', 'base_time_category_id', 'base_time_discipline_id', 'base_time_sport_class_id'], 'base_times_unique_cell');
        });

        Schema::create('base_time_derivation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_time_category_id')->constrained('base_time_categories')->cascadeOnDelete();
            // Kürzere/längere Distanz desselben Bewerbs-Paars, z.B. 100BK → 200BK.
            $table->foreignId('shorter_discipline_id')->constrained('base_time_disciplines')->cascadeOnDelete();
            $table->foreignId('longer_discipline_id')->constrained('base_time_disciplines')->cascadeOnDelete();
            // Override: der Durchschnitts-Wachstumsfaktor wird aus einer anderen Kategorie übernommen
            // (z.B. LC Mixed nutzt den Faktor von LC Men). Null = eigene Kategorie verwenden.
            $table->foreignId('ratio_reference_category_id')->nullable()->constrained('base_time_categories')->nullOnDelete();
            // Manche Herleitungen nutzen nicht den Wachstumsfaktor des eigenen Bewerbs-Paars,
            // sondern den eines anderen (z.B. 1500FR nutzt den Faktor von "400FR to 800FR").
            // Null = eigenes Paar (shorter_discipline_id/longer_discipline_id) verwenden.
            $table->foreignId('ratio_shorter_discipline_id')->nullable()->constrained('base_time_disciplines')->nullOnDelete();
            $table->foreignId('ratio_longer_discipline_id')->nullable()->constrained('base_time_disciplines')->nullOnDelete();
            $table->timestamps();

            $table->unique(['base_time_category_id', 'shorter_discipline_id', 'longer_discipline_id'], 'base_time_derivation_rules_unique_pair');
        });

        Schema::create('qualifying_time_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->boolean('is_active')->default(true);
            $table->date('qualification_period_start')->nullable();
            $table->date('qualification_period_end')->nullable();
            $table->timestamps();
        });

        Schema::create('qualifying_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qualifying_time_list_id')->constrained('qualifying_time_lists')->cascadeOnDelete();
            $table->foreignId('stroke_type_id')->constrained('stroke_types')->restrictOnDelete();
            $table->unsignedSmallInteger('distance');
            $table->enum('gender', ['M', 'F']);
            // z.B. "S2", "SB2", "SM2"
            $table->string('sport_class', 15);
            $table->integer('value_centiseconds')->nullable();
            $table->enum('source', ['MANUAL', 'CALCULATED'])->default('MANUAL');
            $table->timestamps();

            $table->unique(['qualifying_time_list_id', 'stroke_type_id', 'distance', 'gender', 'sport_class'], 'qt_list_event_class_unique');
        });

        Schema::create('qualifying_target_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qualifying_time_list_id')->constrained('qualifying_time_lists')->cascadeOnDelete();
            // z.B. "S2", "SB2", "SM2"
            $table->string('sport_class', 15);
            $table->unsignedSmallInteger('points')->default(100);
            $table->timestamps();

            $table->unique(['qualifying_time_list_id', 'sport_class'], 'qtp_list_class_unique');
        });

        Schema::create('qualifying_excluded_disciplines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_time_discipline_id')->unique()->constrained('base_time_disciplines')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('championships', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('short_name', 50)->nullable();
            // EC / WC / PARALYMPICS / OTHER
            $table->string('type', 20);
            $table->unsignedSmallInteger('year');
            $table->string('course', 3)->default('LCM');
            $table->date('qualification_start');
            $table->date('qualification_end');
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['year', 'type']);
        });

        Schema::create('championship_standards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('championship_id')->constrained('championships')->cascadeOnDelete();
            $table->foreignId('stroke_type_id')->constrained('stroke_types')->restrictOnDelete();
            $table->unsignedSmallInteger('distance');
            $table->enum('gender', ['M', 'F']);
            // z.B. "S7", "SB4", "SM3"
            $table->string('sport_class', 15);
            $table->integer('mqs_centiseconds')->nullable();
            $table->integer('met_centiseconds')->nullable();
            $table->decimal('obsv_percent', 5, 2)->nullable();
            $table->integer('obsv_centiseconds')->nullable();
            $table->boolean('obsv_is_manual')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['championship_id', 'stroke_type_id', 'distance', 'gender', 'sport_class'], 'championship_standards_unique_combo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_standards');
        Schema::dropIfExists('championships');
        Schema::dropIfExists('qualifying_excluded_disciplines');
        Schema::dropIfExists('qualifying_target_points');
        Schema::dropIfExists('qualifying_times');
        Schema::dropIfExists('qualifying_time_lists');
        Schema::dropIfExists('base_time_derivation_rules');
        Schema::dropIfExists('base_times');
        Schema::dropIfExists('base_time_versions');
        Schema::dropIfExists('base_time_sport_classes');
        Schema::dropIfExists('base_time_disciplines');
        Schema::dropIfExists('base_time_categories');
    }
};

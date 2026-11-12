<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cups und Veranstaltungen mit Abschnitten, Gebühren, Vereinen, Punktesystemen, Wettkämpfen und Wertungsgruppen.
 * cups steht hier, weil meets darauf verweist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cups', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->string('name', 150);
            // Verweist auf die bestehende ÖBSV-1000-Punkte-Tabelle (Wiederverwendung,
            // keine eigene Punkteberechnung im Cup-Modul).
            $table->foreignId('base_time_version_id')->constrained('base_time_versions');
            // Anzahl Wertungsrunden
            $table->unsignedTinyInteger('rounds_count')->default(1);
            // beste X Tageswertungen für die Gesamtwertung
            $table->unsignedTinyInteger('best_of_count')->default(1);
            // Punktgrenze, ab der ein Athlet automatisch in die Top-Gruppe fällt (Punkt 8 der Spec).
            $table->unsignedSmallInteger('top_group_points_threshold')->default(450);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('meets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cup_id')->nullable()->constrained('cups')->nullOnDelete();
            $table->foreignId('qualifying_time_list_id')->nullable()->constrained('qualifying_time_lists')->nullOnDelete();
            $table->string('name');
            $table->string('city', 100)->nullable();
            $table->foreignId('nation_id')->index()->constrained('nations')->restrictOnDelete();
            // LENEX 3.0 Sektion 6.4 — alle gültigen course-Werte
            $table->enum('course', ['LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33', 'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN'])->default('LCM');
            $table->date('start_date')->index();
            $table->date('end_date')->nullable();
            // Meldeschluss: nach diesem Datum dürfen Vereins-User keine
            // Meldungen mehr anlegen, bearbeiten oder löschen.
            // Admins sind ausgenommen.
            // NULL = kein Meldeschluss gesetzt.
            $table->date('entries_deadline')->nullable();
            $table->timestamp('entries_reopened_until')->nullable();
            $table->foreignId('entries_reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entries_reopened_at')->nullable();
            $table->string('organizer')->nullable();
            $table->integer('altitude')->default(0);
            // LENEX 3.0 MEET.timing
            $table->enum('timing', ['AUTOMATIC', 'SEMIAUTOMATIC', 'MANUAL3', 'MANUAL2', 'MANUAL1'])->default('AUTOMATIC');
            // LENEX 3.0 MEET.entrytype
            $table->enum('entry_type', ['OPEN', 'INVITATION'])->nullable();
            // LENEX 3.0 MEET.status
            $table->enum('lenex_status', ['ENTRIES', 'SEEDED', 'RUNNING', 'OFFICIAL'])->nullable();
            // Applikations-Flag: Clubs können Athleten melden
            // entspricht MEET.entrytype = OPEN in LENEX
            $table->boolean('is_open')->default(false);
            $table->boolean('wps_approved')->default(false);
            $table->boolean('keep_file_places')->default(false);
            $table->boolean('medal_reduction_rule')->default(true);
            $table->timestamp('records_checked_at')->nullable();
            $table->string('wps_approved_note')->nullable();
            $table->string('livetiming_url')->nullable();
            $table->boolean('is_published')->default(false);
            // Global unique ID von swimrankings.net
            $table->string('swrid')->nullable()->index();
            // LENEX Import-Referenz: nicht unique — Meetmanager vergibt pro Export neu
            $table->string('lenex_meet_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('meet_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->date('date')->nullable();
            $table->time('daytime')->nullable();
            $table->timestamps();

            $table->unique(['meet_id', 'number']);
        });

        Schema::create('meet_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->unsignedInteger('session_number')->nullable();
            $table->string('type', 30);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();

            $table->unique(['meet_id', 'session_number', 'type']);
        });

        Schema::create('meet_club', function (Blueprint $table) {
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();

            $table->primary(['meet_id', 'club_id']);
        });

        Schema::create('meet_point_system', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->foreignId('point_system_id')->constrained('point_systems')->cascadeOnDelete();
            // Übersteuert die automatische Versionszuordnung nach Wettkampfdatum.
            // null = automatisch ermitteln. Nur für das Punktesystem WPS relevant.
            $table->foreignId('wps_point_version_id')->nullable()->constrained('wps_point_versions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['meet_id', 'point_system_id'], 'meet_point_system_unique');
        });

        Schema::create('swim_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained('meets')->cascadeOnDelete();
            $table->foreignId('stroke_type_id')->index()->constrained('stroke_types')->restrictOnDelete();
            // EVENT Felder
            $table->integer('event_number')->nullable();
            $table->integer('session_number')->default(1);
            // LENEX EVENT.gender: M, F, A (all = default), X (mixed, Staffel)
            $table->enum('gender', ['M', 'F', 'A', 'X'])->default('A');
            // LENEX EVENT.round
            $table->enum('round', ['TIM', 'FHT', 'FIN', 'SEM', 'QUA', 'PRE', 'SOP', 'SOS', 'SOQ', 'TIMETRIAL'])->default('TIM');
            // LENEX EVENT.status
            $table->enum('lenex_status', ['ENTRIES', 'SEEDED', 'RUNNING', 'UNOFFICIAL', 'OFFICIAL'])->nullable();
            // SWIMSTYLE Felder
            $table->integer('distance');
            // relaycount: 1 = Einzel, >1 = Staffel (Anzahl Schwimmer)
            $table->integer('relay_count')->default(1);
            $table->unsignedInteger('fee_cents')->nullable();
            // SWIMSTYLE.technique (optional)
            $table->enum('technique', ['DIVE', 'GLIDE', 'KICK', 'PULL', 'START', 'TURN'])->nullable();
            // SWIMSTYLE.code (max. 6 Zeichen, nur wenn stroke = UNKNOWN)
            $table->string('style_code', 20)->nullable();
            // SWIMSTYLE.name (nur wenn stroke = UNKNOWN)
            $table->string('style_name')->nullable();
            // Para: Leerzeichen-getrennte Klassenliste z.B. "S1 S2 S3" oder "SB14"
            $table->string('sport_classes')->nullable();
            $table->boolean('is_scored')->default(true);
            // Referenz auf vorherigen Event (Vorlauf → Finale), -1 = kein Vorgänger
            $table->integer('prev_event_id')->nullable();
            // EVENT.timing (überschreibt Meet-Timing)
            $table->enum('timing', ['AUTOMATIC', 'SEMIAUTOMATIC', 'MANUAL3', 'MANUAL2', 'MANUAL1'])->nullable();
            // LENEX Import-Referenz: nicht unique — Meetmanager vergibt pro Export neu
            $table->string('lenex_event_id')->nullable();
            $table->timestamps();

            $table->index(['meet_id', 'gender', 'distance']);
            $table->index(['meet_id', 'lenex_event_id']);
        });

        Schema::create('scoring_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('swim_event_id')->constrained('swim_events')->cascadeOnDelete();
            $table->string('name', 100);
            // M / F / X / A
            $table->char('gender', 1)->default('A');
            // "1,2,3,4,5,6,7,8" — leer = alle Klassen
            $table->string('sport_classes', 100)->nullable();
            $table->unsignedSmallInteger('age_min')->nullable();
            $table->unsignedSmallInteger('age_max')->nullable();
            $table->string('regional_associations', 100)->nullable();
            $table->string('regional_mode', 10)->nullable();
            $table->string('levels', 100)->nullable();
            // OSTM / OM
            $table->string('title', 10)->nullable();
            $table->boolean('awards_medals')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('lenex_agegroup_id')->nullable();
            $table->timestamps();

            $table->index(['swim_event_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoring_groups');
        Schema::dropIfExists('swim_events');
        Schema::dropIfExists('meet_point_system');
        Schema::dropIfExists('meet_club');
        Schema::dropIfExists('meet_fees');
        Schema::dropIfExists('meet_sessions');
        Schema::dropIfExists('meets');
        Schema::dropIfExists('cups');
    }
};

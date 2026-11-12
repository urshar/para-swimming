<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stammdaten ohne fachliche Abhängigkeiten: Nationen, Lagen, Ausnahmecodes, Kader, Altersgruppen,
 * Sportklassen-Gruppen, Punktesysteme, WPS-Punkte, Dokumente und Audit-Log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nations', function (Blueprint $table) {
            $table->id();
            // IOC-Code: AUT, GER, …
            $table->string('code', 3)->unique();
            // Österreich
            $table->string('name_de');
            // Austria
            $table->string('name_en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stroke_types', function (Blueprint $table) {
            $table->id();
            // Interner Code — von uns vergeben, stable, unique
            $table->string('code', 20)->unique();
            // LENEX 3.0 stroke-Wert — exakt so wie im XML
            // Nicht unique: theoretisch könnten verschiedene Meetmanager
            // denselben Stroke-Code für unterschiedliche Dinge verwenden
            $table->string('lenex_code', 20)->index();
            // Freistil, Rücken, Brust, Schmetterling …
            $table->string('name_de');
            // Freestyle, Backstroke, Breaststroke, Butterfly …
            $table->string('name_en');
            // Kategorie für Gruppierung in der Anwendung
            // standard = normales Schwimmen, fin = Flossenschwimmen
            $table->enum('category', ['standard', 'fin', 'special'])->default('standard')->index();
            // Ist es ein Staffel-Stroke? (IMRELAY, MEDLEY wenn relaycount > 1)
            $table->boolean('is_relay_stroke')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exception_codes', function (Blueprint $table) {
            $table->id();
            // Offizieller WPS-Code: H, Y, E, A, T, B, 0, 1, 2, 3, 4, 5, 7, 8, 9, 12, +
            $table->string('code', 5)->unique();
            // "Hearing Impaired – Light or Signal Required"
            $table->string('name_en');
            // "Hörbehindert – Licht oder Signal erforderlich"
            $table->string('name_de');
            $table->text('description_en')->nullable();
            $table->text('description_de')->nullable();
            // WPS Regelreferenz: "11.1.6, 11.1.7, 11.1.8, 1.4.4.3"
            $table->string('wps_rules')->nullable();
            // Für welche Schwimmstile gilt dieser Code?
            // null = allgemein (unabhängig vom Stil: H, Y, E, A, T, B)
            // 'BACK', 'BREAST', 'FLY' = stiltspezifisch
            // 'BREAST_UPPER', 'BREAST_LOWER' = für Brust differenziert
            $table->string('applies_to', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('kader_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_de', 150);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('age_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name_de', 100);
            // Beide nullable = offenes Intervall (z.B. "Offen" hat nur min_age).
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sport_class_groups', function (Blueprint $table) {
            $table->id();
            // PI, VI, II, T21, HI, TOP
            $table->string('code', 20)->unique();
            $table->string('name_de', 150);
            // true = Top-Gruppe (keine festen Sportklassen-Mitglieder)
            $table->boolean('is_virtual')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sport_class_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_class_group_id')->constrained('sport_class_groups')->cascadeOnDelete();
            // z.B. "S4", "SB9", "SM14"
            $table->string('sport_class', 10)->unique();
            $table->timestamps();
        });

        Schema::create('point_systems', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Eindeutiger Code, z.B. WA, WPS, OBSV1000
            $table->string('code', 20)->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('wps_point_versions', function (Blueprint $table) {
            $table->id();
            // Anzeigename, z.B. "WPS 2026"
            $table->string('label', 100);
            $table->unsignedSmallInteger('year');
            // Versionsbezeichnung des Herausgebers (Blatt "version control" der Quelldatei)
            $table->string('version', 20)->nullable();
            $table->string('source')->nullable();
            // false = abgeleitete/inoffizielle Zusammenstellung
            $table->boolean('official')->default(true);
            // active = verwendbar, archived = nur noch für historische Ergebnisse
            $table->string('status', 20)->default('active')->index();
            $table->date('valid_from')->nullable()->index();
            // null = bis auf Weiteres gültig
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->unique(['year', 'version'], 'wps_point_versions_unique_release');
        });

        Schema::create('wps_point_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wps_point_version_id')->constrained('wps_point_versions')->cascadeOnDelete();
            // LCM oder SCM. Kein DB-Enum, analog zu base_time_categories.course —
            // Validierung erfolgt im Form Request bzw. Importservice.
            $table->string('course', 3);
            // M oder F. Staffel-Geschlechter (X/A) sind nicht vorgesehen, siehe relay_count.
            $table->string('gender', 1);
            $table->foreignId('stroke_type_id')->constrained('stroke_types')->restrictOnDelete();
            $table->unsignedSmallInteger('distance');
            // WPS veröffentlicht derzeit keine Staffelparameter. Die Spalte ist vorhanden,
            // damit spätere Veröffentlichungen ohne Migration importierbar sind.
            $table->unsignedTinyInteger('relay_count')->default(1);
            $table->string('sport_class', 15);
            $table->decimal('parameter_a', 14, 6);
            $table->decimal('parameter_b', 14, 6);
            $table->decimal('parameter_c', 14, 6);
            // false = aus LCM-Daten abgeleitet (SCM), true = offiziell veröffentlicht.
            // Bestimmt den Berechnungstyp am Ergebnis (official/estimated).
            $table->boolean('official')->default(true);
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['wps_point_version_id', 'course', 'gender', 'sport_class'], 'wps_params_lookup');
            $table->unique(['wps_point_version_id', 'course', 'gender', 'stroke_type_id', 'distance', 'relay_count', 'sport_class'], 'wps_params_unique_combo');
        });

        Schema::create('wps_scm_conversion_factors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stroke_type_id')->constrained('stroke_types')->restrictOnDelete();
            // null = gilt für alle Strecken dieses Schwimmstils
            $table->unsignedSmallInteger('distance')->nullable();
            // null = Sammelwert für alle Sportklassen
            $table->string('sport_class', 15)->nullable();
            // null = geschlechtsunabhängig
            $table->string('gender', 1)->nullable();
            // p_LCM = p_SCM × factor. Stets > 1, da auf der Kurzbahn schneller geschwommen
            // wird. Fünf Nachkommastellen: die Werte liegen typischerweise zwischen 1,01 und
            // 1,04, eine gröbere Auflösung verschiebt die Punkte spürbar.
            $table->decimal('factor', 8, 5);
            // own_data | literature | manual
            $table->string('source', 50);
            // Anzahl der Athleten hinter dem Faktor — nur bei own_data gesetzt
            $table->integer('sample_size')->nullable();
            // high | medium | low
            $table->string('confidence_level', 20)->default('low');
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['stroke_type_id', 'distance', 'sport_class', 'gender'], 'wps_scm_factors_unique_combo');
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('documentable_type')->nullable();
            $table->unsignedBigInteger('documentable_id')->nullable();
            $table->enum('category', ['INVITATION', 'START_LIST', 'RESULTS', 'REGULATION', 'FORM']);
            $table->string('title');
            // null = sprachneutrale Fassung, wird in beiden Sprachen gezeigt (§4.1).
            $table->string('locale', 5)->nullable();
            $table->string('path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_public')->default(false);
            $table->dateTime('published_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'locale']);
            $table->index(['documentable_type', 'documentable_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('event', 20);
            $table->json('changes');
            $table->string('source', 20);
            $table->string('source_detail')->nullable();
            $table->unsignedBigInteger('athlete_id')->nullable();
            $table->unsignedBigInteger('club_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['athlete_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id', 'created_at']);
            $table->index(['club_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('wps_scm_conversion_factors');
        Schema::dropIfExists('wps_point_parameters');
        Schema::dropIfExists('wps_point_versions');
        Schema::dropIfExists('point_systems');
        Schema::dropIfExists('sport_class_group_members');
        Schema::dropIfExists('sport_class_groups');
        Schema::dropIfExists('age_groups');
        Schema::dropIfExists('kader_types');
        Schema::dropIfExists('exception_codes');
        Schema::dropIfExists('stroke_types');
        Schema::dropIfExists('nations');
    }
};

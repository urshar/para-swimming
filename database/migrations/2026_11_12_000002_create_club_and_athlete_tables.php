<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vereine, Trainer, Benutzerrechte und Athleten samt Historien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name', 40)->nullable();
            // LENEX CLUB.code — offizieller Vereinscode
            $table->string('code', 10)->nullable();
            $table->foreignId('nation_id')->index()->constrained('nations')->restrictOnDelete();
            // LENEX CLUB.type
            $table->enum('type', ['CLUB', 'NATIONALTEAM', 'REGIONALTEAM', 'UNATTACHED', 'VERBAND'])->default('CLUB');
            $table->enum('regional_association', ['WBSV', 'BBSV', 'KBSV', 'NOEVSV', 'OOEBSV', 'SBSV', 'STBSV', 'TBSV', 'VBSV'])->nullable()->index();
            // LENEX CLUB.swrid — global unique ID von swimrankings.net
            // fehlt in früherer Version, aber in LENEX 3.0 für CLUB definiert
            $table->string('swrid')->nullable()->index();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('address_street')->nullable();
            $table->string('address_zip', 20)->nullable();
            $table->string('address_city')->nullable();
            // ISO 3166-1 alpha-3
            $table->string('address_country', 3)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['nation_id', 'code'], 'clubs_nation_code_unique');
        });

        Schema::create('coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->restrictOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['club_id', 'last_name']);
        });

        Schema::create('coach_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('coaches')->cascadeOnDelete();
            // education = Ausbildung, training = Fortbildung
            $table->string('kind', 20);
            // App\Enums\CoachLevel, nur bei Ausbildung
            $table->string('level', 2)->nullable();
            $table->string('title')->nullable();
            $table->date('completed_on');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['coach_id', 'completed_on']);
        });

        Schema::create('user_module_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module', 40);
            $table->string('read_scope', 10)->default('none');
            $table->string('write_scope', 10)->default('none');
            $table->timestamps();

            $table->unique(['user_id', 'module']);
        });

        Schema::create('athletes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nation_id')->index()->constrained('nations')->restrictOnDelete();
            // Aktiver Verein — wird via AthleteClubHistory verwaltet.
            // Dieses Feld zeigt immer den aktuellen Verein (Convenience-Feld).
            // Bei Vereinswechsel: club_history anlegen UND dieses Feld updaten.
            $table->foreignId('club_id')->nullable()->index()->constrained('clubs')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            // "van den" etc.
            $table->string('name_prefix', 50)->nullable();
            $table->date('birth_date')->nullable();
            // LENEX 3.0: M, F, N (non binary)
            $table->enum('gender', ['M', 'F', 'N'])->default('M');
            // Lizenznummern
            // Nationale Lizenznummer
            $table->string('license')->nullable()->index();
            // SDMS ID von World Para Swimming
            $table->string('license_ipc')->nullable()->index();
            // swimrankings.net Global-ID
            $table->string('swrid')->nullable()->index();
            // LENEX 3.0 ATHLETE.status
            $table->enum('status', ['EXHIBITION', 'FOREIGNER', 'ROOKIE'])->nullable();
            $table->string('disability_group', 5)->nullable()->index();
            $table->string('disability_subgroup', 1)->nullable();
            // Aktiv-Status
            $table->boolean('is_active')->default(true)->index();
            // Internes Notizfeld
            $table->text('notes')->nullable();
            // Kontakt
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            // Adresse
            $table->string('address_street')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_zip', 20)->nullable();
            // ISO 3166-1 alpha-3
            $table->string('address_country', 3)->nullable();
            // Level (ÖBSV-Einstufung) — aktueller Wert als Convenience-Feld.
            // Vollständige History → athlete_level_history Tabelle.
            $table->string('level', 50)->nullable();
            $table->date('last_medical_check_at')->nullable();
            $table->date('next_medical_check_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
        });

        Schema::create('athlete_sport_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->index()->constrained('athletes')->cascadeOnDelete();
            // LENEX Kategorie-Mapping:
            // S  → LENEX free   (Freistil, Rücken, Schmetterling)
            // SB → LENEX breast (Brustschwimmen)
            // SM → LENEX medley (Lagen)
            $table->enum('category', ['S', 'SB', 'SM']);
            // Klassennummer als String — erlaubt: 0-15, GER.AB, GER.GB
            $table->string('class_number', 10);
            // Vollständiger Display-String: "S4", "SB3", "SM14", "GER.AB"
            $table->string('sport_class', 15)->index();
            // Gültigkeitsbereich:
            // INTL = international gültig (Athlet hat SDMS/IPC-ID)
            // NAT  = nur national gültig (ÖBSV)
            $table->enum('classification_scope', ['INTL', 'NAT'])->default('INTL');
            // Klassifikationsstatus — entspricht LENEX 3.0 *status Attributen,
            // erweitert um FRD und NE:
            // NEW       = Erstklassifizierung, noch nicht bestätigt
            // CONFIRMED = Bestätigt (international: LENEX CONFIRMED)
            // REVIEW    = Muss überprüft werden (LENEX REVIEW / OBSERVATION)
            // FRD       = Fixed Review Date → frd_year gibt das Jahr an
            // NE        = Not Eligible (nicht klassifizierbar)
            $table->enum('classification_status', ['NEW', 'CONFIRMED', 'REVIEW', 'FRD', 'NE'])->nullable();
            // Nur bei classification_status = FRD: Jahr des nächsten Review-Termins
            $table->unsignedSmallInteger('frd_year')->nullable();
            $table->timestamps();

            $table->unique(['athlete_id', 'category']);
        });

        Schema::create('athlete_level_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->index()->constrained('athletes')->cascadeOnDelete();
            // User, der die Änderung vorgenommen hat (users-Tabelle existiert bereits durch Laravel)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // z.B. "1", "2", "Elite", "Talent" — vom ÖBSV definiert
            $table->string('level', 50);
            // Vorheriger Level zur Nachvollziehbarkeit
            $table->string('previous_level', 50)->nullable();
            // Datum der Einstufung (kann vom created_at abweichen)
            $table->date('changed_at')->index();
            // Begründung / Kommentar
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('athlete_club_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->index()->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('club_id')->index()->constrained('clubs')->restrictOnDelete();
            $table->date('joined_at');
            // null = aktuell aktiv
            $table->date('left_at')->nullable();
            $table->boolean('is_active')->default(false)->index();
            // Grund der Ummeldung etc.
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['athlete_id', 'is_active']);
        });

        Schema::create('athlete_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->index()->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('exception_code_id')->constrained('exception_codes')->cascadeOnDelete();
            // Für welche Sport-Kategorie gilt diese Exception beim Athleten?
            // null = allgemein (z.B. H, Y, A, T, B — nicht stilabhängig)
            // 'S', 'SB', 'SM' = nur für diese Kategorie
            $table->enum('category', ['S', 'SB', 'SM'])->nullable();
            // Optionale Notiz (z.B. Ablaufdatum der Klassifizierung, Bemerkung)
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['athlete_id', 'exception_code_id', 'category'], 'athlete_exception_unique');
        });

        Schema::create('athlete_kader_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->index()->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('kader_type_id')->constrained('kader_types')->restrictOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['valid_from', 'valid_until']);
        });

        Schema::create('athlete_duplicate_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('other_athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['athlete_id', 'other_athlete_id'], 'athlete_duplicate_dismissals_pair_unique');
        });

        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->foreignId('athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignId('from_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->json('values');
            $table->text('reason');
            $table->string('status', 20)->default('open');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('response')->nullable();
            $table->timestamps();

            $table->index(['athlete_id', 'type', 'status']);
            $table->index(['club_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('club_id')->references('id')->on('clubs')->nullOnDelete();
            $table->foreign('coach_id')->references('id')->on('coaches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_club_id_foreign');
            $table->dropForeign('users_coach_id_foreign');
        });
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('athlete_duplicate_dismissals');
        Schema::dropIfExists('athlete_kader_memberships');
        Schema::dropIfExists('athlete_exceptions');
        Schema::dropIfExists('athlete_club_history');
        Schema::dropIfExists('athlete_level_history');
        Schema::dropIfExists('athlete_sport_classes');
        Schema::dropIfExists('athletes');
        Schema::dropIfExists('user_module_permissions');
        Schema::dropIfExists('coach_qualifications');
        Schema::dropIfExists('coaches');
        Schema::dropIfExists('clubs');
    }
};

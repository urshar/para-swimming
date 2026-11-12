<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekorde mit Zwischenzeiten, Staffel-Mitgliedern und Import-Prüfposten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swim_records', function (Blueprint $table) {
            $table->id();
            // Verknüpfungen
            $table->foreignId('stroke_type_id')->index()->constrained('stroke_types')->restrictOnDelete();
            $table->foreignId('nation_id')->nullable()->index()->constrained('nations')->nullOnDelete();
            $table->foreignId('meet_nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->foreignId('athlete_id')->nullable()->index()->constrained('athletes')->nullOnDelete();
            // Verknüpfung zum Result aus dem dieser Rekord stammt (optional)
            $table->foreignId('result_id')->nullable()->constrained('results')->nullOnDelete();
            $table->foreignId('relay_result_id')->nullable()->constrained('relay_results')->nullOnDelete();
            // Zeigt auf den neueren Rekord, der diesen ersetzt hat (null = noch aktuell)
            $table->foreignId('superseded_by_id')->nullable()->index()->constrained('swim_records')->nullOnDelete();
            // Zeigt auf den Vorgänger-Rekord, den dieser ersetzt hat (null = erster Rekord)
            $table->foreignId('supersedes_id')->nullable()->index()->constrained('swim_records')->nullOnDelete();
            $table->foreignId('club_id')->nullable()->constrained('clubs')->nullOnDelete();
            // RECORDLIST.type: WR, OR, ER, PAR, AFR, AR, OCR, CWR, AUT, GER …
            $table->string('record_type', 20);
            // SWIMSTYLE Felder
            $table->string('sport_class', 15);
            $table->enum('gender', ['M', 'F', 'X']);
            // Alle LENEX 3.0 course-Werte (Sektion 6.4)
            $table->enum('course', ['LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33', 'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN'])->default('LCM');
            $table->integer('distance');
            $table->integer('relay_count')->default(1);
            $table->boolean('from_split')->default(false);
            // Schwimmzeit in Hundertstelsekunden
            $table->integer('swim_time');
            $table->enum('record_status', ['APPROVED', 'PENDING', 'INVALID', 'APPROVED.HISTORY', 'PENDING.HISTORY', 'TARGETTIME'])->default('APPROVED')->index();
            // Unser internes Flag — schnellerer Query ohne JOIN auf superseded_by_id
            $table->boolean('is_current')->default(true)->index();
            $table->date('set_date')->nullable();
            $table->string('meet_name')->nullable();
            $table->string('meet_city')->nullable();
            $table->enum('meet_course', ['LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33', 'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN'])->nullable();
            // RECORD.comment
            $table->string('comment')->nullable();
            $table->timestamps();

            $table->index(['record_type', 'sport_class', 'gender', 'course', 'distance', 'is_current'], 'records_lookup_idx');
        });

        Schema::create('record_splits', function (Blueprint $table) {
            $table->id();
            // Verknüpfung zum Rekord — bei Löschen eines Rekords werden Splits mitgelöscht
            // Historische Rekorde bleiben in swim_records erhalten (is_current=false),
            // daher bleiben auch ihre Splits erhalten
            $table->foreignId('swim_record_id')->index()->constrained('swim_records')->cascadeOnDelete();
            // Distanz in Metern an der die Split-Zeit gemessen wurde
            $table->integer('distance');
            // Kumulierte Zeit in Hundertstelsekunden
            $table->integer('split_time');
            $table->timestamps();

            $table->unique(['swim_record_id', 'distance']);
        });

        Schema::create('relay_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('swim_record_id')->constrained('swim_records')->cascadeOnDelete();
            // RELAYPOSITION number="1"
            $table->unsignedTinyInteger('position');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('birth_date')->nullable();
            // M / F
            $table->char('gender', 1)->nullable();
            $table->foreignId('athlete_id')->nullable()->constrained('athletes')->nullOnDelete();
            $table->timestamps();

            $table->index(['swim_record_id', 'position']);
        });

        Schema::create('import_review_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->foreignId('athlete_id')->nullable()->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('current_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignId('lenex_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignId('swim_record_id')->nullable()->constrained('swim_records')->nullOnDelete();
            $table->string('source')->nullable();
            $table->json('details')->nullable();
            $table->string('status', 10)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['athlete_id', 'type']);
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_review_items');
        Schema::dropIfExists('relay_team_members');
        Schema::dropIfExists('record_splits');
        Schema::dropIfExists('swim_records');
    }
};

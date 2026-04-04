<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * swim_records — basierend auf LENEX 3.0 Elemente RECORDLIST + RECORD
     *
     * HISTORIE-LOGIK:
     *   - Jeder Rekord ist ein eigener Datensatz
     *   - Wird ein Rekord überboten, bekommt der alte:
     *       is_current = false
     *       superseded_by_id = ID des neuen Rekords
     *       record_status = 'APPROVED.HISTORY'
     *   - Über superseded_by_id kann die vollständige Rekordkette
     *     vorwärts und rückwärts traversiert werden
     *   - superseded_id zeigt auf den direkten Vorgänger (optional,
     *     erleichtert Rückwärts-Navigation)
     *
     * SPLITS:
     *   Gespeichert in record_splits Tabelle (000010b)
     *   Analog zu result_splits, aber für Rekord-Einträge
     *
     * record_type Werte (LENEX 3.0 RECORDLIST.type):
     *   WR, OR, ER, PAR, AFR, AR, OCR, CWR
     *   + IOC-Dreilettercode für Nationalrekorde (AUT, GER, SUI …)
     *   + föderationsspezifisch mit Präfix (SUI.RZW …)
     *   → als String gespeichert
     *
     * course: alle LENEX 3.0 Werte (Sektion 6.4)
     */
    public function up(): void
    {
        Schema::create('swim_records', function (Blueprint $table) {
            $table->id();

            // Verknüpfungen
            $table->foreignId('stroke_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('nation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('athlete_id')->nullable()->constrained()->nullOnDelete();

            // Verknüpfung zum Result aus dem dieser Rekord stammt (optional)
            $table->foreignId('result_id')->nullable()->constrained('results')->nullOnDelete();

            // ── Historie-Verknüpfungen ────────────────────────────────────────
            // Zeigt auf den neueren Rekord, der diesen ersetzt hat (null = noch aktuell)
            $table->foreignId('superseded_by_id')
                ->nullable()
                ->constrained('swim_records')
                ->nullOnDelete();

            // Zeigt auf den Vorgänger-Rekord, den dieser ersetzt hat (null = erster Rekord)
            $table->foreignId('supersedes_id')
                ->nullable()
                ->constrained('swim_records')
                ->nullOnDelete();

            $table->foreignId('club_id')
                ->nullable()
                ->constrained('clubs')
                ->nullOnDelete();

            // ── Rekord-Klassifizierung ────────────────────────────────────────
            // RECORDLIST.type: WR, OR, ER, PAR, AFR, AR, OCR, CWR, AUT, GER …
            $table->string('record_type', 20);

            // SWIMSTYLE Felder
            $table->string('sport_class', 15);
            $table->enum('gender', ['M', 'F', 'X']);

            // Alle LENEX 3.0 course-Werte (Sektion 6.4)
            $table->enum('course', [
                'LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33',
                'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN',
            ])->default('LCM');

            $table->integer('distance');
            $table->integer('relay_count')->default(1);

            // ── Leistung ──────────────────────────────────────────────────────
            // Schwimmzeit in Hundertstelsekunden
            $table->integer('swim_time');

            // ── LENEX 3.0 RECORD.status ───────────────────────────────────────
            $table->enum('record_status', [
                'APPROVED',           // Bestätigt und aktuell gültig
                'PENDING',            // Wartet auf Ratifizierung
                'INVALID',            // Ungültig (Ratifizierung fehlgeschlagen)
                'APPROVED.HISTORY',   // Früher gültig, inzwischen überboten
                'PENDING.HISTORY',    // Wartete auf Ratifizierung, inzwischen überboten
                'TARGETTIME',         // Zielzeit, noch kein Rekord
            ])->default('APPROVED');

            // Unser internes Flag — schnellerer Query ohne JOIN auf superseded_by_id
            $table->boolean('is_current')->default(true);

            // ── Wettkampf-Info (LENEX MEETINFO) ───────────────────────────────
            $table->date('set_date')->nullable();
            $table->string('meet_name')->nullable();
            $table->string('meet_city')->nullable();
            $table->enum('meet_course', [
                'LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33',
                'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN',
            ])->nullable();

            // RECORD.comment
            $table->string('comment')->nullable();

            $table->timestamps();

            // Schnelle Suche nach aktuellem Rekord einer Kombination
            $table->index([
                'record_type', 'sport_class', 'gender',
                'course', 'distance', 'is_current',
            ], 'records_lookup_idx');

            $table->index('is_current');
            $table->index('record_status');
            $table->index('athlete_id');
            $table->index('stroke_type_id');
            $table->index('nation_id');
            $table->index('superseded_by_id');
            $table->index('supersedes_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swim_records');
    }
};

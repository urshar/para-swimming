<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classifications-History eines Athleten.
     *
     * Pro Klassifikationsereignis: 1 medizinischer + 2 technische Klassifizierer.
     *
     * Scope-Logik:
     *   INTL = Athlet hat SDMS/IPC-ID → international gültig (World Para Swimming)
     *   NAT  = kein SDMS → nur national gültig (ÖBSV)
     *
     * Status-Werte (gleich für INTL und NAT):
     *   NEW       = Erstklassifizierung, noch nicht bestätigt
     *   CONFIRMED = Bestätigt / gültig
     *   REVIEW    = Überprüfung ohne fixen Datum
     *   FRD       = Fixed Review Date → frd_year gibt das Jahr an
     *   NE        = Not Eligible (nicht klassifizierbar)
     */
    public function up(): void
    {
        Schema::create('athlete_classifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();

            // Die drei Klassifizierer des Ereignisses
            $table->foreignId('med_classifier_id')
                ->nullable()
                ->constrained('classifiers')
                ->nullOnDelete();

            $table->foreignId('tech1_classifier_id')
                ->nullable()
                ->constrained('classifiers')
                ->nullOnDelete();

            $table->foreignId('tech2_classifier_id')
                ->nullable()
                ->constrained('classifiers')
                ->nullOnDelete();

            // Wann und wo
            $table->date('classified_at');
            $table->string('location')->nullable();

            // Ergebnis Sportklassen nach dieser Klassifikation (analog zu athlete_sport_classes)
            // jede Kategorie kann null sein, wenn der Athlet diese nicht schwimmt
            $table->string('result_s', 15)->nullable();  // z.B. "S4", "S14"
            $table->string('result_sb', 15)->nullable();  // z.B. "SB3", "SB14"
            $table->string('result_sm', 15)->nullable();  // z.B. "SM4", "SM14"

            // Gültigkeitsbereich: INTL (mit SDMS) oder NAT (nur national)
            $table->enum('classification_scope', ['INTL', 'NAT'])->default('INTL');

            // Klassifikationsstatus
            $table->enum('classification_status', ['NEW', 'CONFIRMED', 'REVIEW', 'FRD', 'NE'])->nullable();

            // Nur bei FRD: das Jahr des nächsten Review-Termins
            $table->smallInteger('frd_year')->nullable()->unsigned();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('athlete_id');
            $table->index('classified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_classifications');
    }
};

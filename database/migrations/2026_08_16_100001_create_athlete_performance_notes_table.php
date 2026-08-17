<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * athlete_performance_notes — Notizen zur Leistungsentwicklung
     * (Spec "WPS Rankings" §7.5).
     *
     * Halten fest, was hinter einer Verbesserung oder Verschlechterung steht: Krankheit,
     * Verletzung, Umklassifizierung, geänderter Trainingsumfang. Ohne diese Angaben ist eine
     * Zahlenreihe nicht deutbar — ein Einbruch um hundert Punkte sieht gleich aus, ob ihm
     * eine Schulterverletzung oder ein Trainingsfehler zugrunde liegt.
     *
     * Eigene Tabelle und nicht `results.comment`: Jenes Feld gehört LENEX und wird beim
     * Ergebnisimport überschrieben (DSQ-Begründungen, "Ratification pending"). Eine Notiz
     * dort wäre nach dem nächsten Import verschwunden.
     *
     * result_id ist nullable, dazu ein eigenes Datum: Nicht jede Ursache hängt an einem
     * Start. "Sechs Wochen Trainingspause wegen Schulterverletzung" betrifft einen Zeitraum,
     * nicht ein Ergebnis. Mit beidem lässt sich eine Notiz an einen konkreten Start hängen
     * oder an einen Zeitpunkt.
     */
    public function up(): void
    {
        Schema::create('athlete_performance_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();

            // Wird das Ergebnis gelöscht, bleibt die Notiz mit ihrem Datum bestehen: Die
            // Beobachtung über den Athleten gilt weiter, auch wenn der Start entfällt.
            $table->foreignId('result_id')->nullable()->constrained()->nullOnDelete();

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
    }
};

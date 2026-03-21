<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zwei Tabellen:
     *
     * 1. exception_codes  — Lookup-Tabelle aller WPS Exception-Codes (wird per Seeder gefüllt)
     *    Codes: H, Y, E, A, T, B, 0, 1, 2, 3, 4, 5, 7, 8, 9, 12, +
     *
     * 2. athlete_exceptions  — Zuordnung Athlet → Exception-Codes (n:m)
     *    Ein Athlet kann mehrere Codes haben.
     *    Gilt für eine bestimmte Sport-Kategorie (S / SB / SM) oder allgemein (null).
     */
    public function up(): void
    {
        // ── 1. Lookup ──────────────────────────────────────────────────────────
        Schema::create('exception_codes', function (Blueprint $table) {
            $table->id();

            // Offizieller WPS-Code: H, Y, E, A, T, B, 0, 1, 2, 3, 4, 5, 7, 8, 9, 12, +
            $table->string('code', 5)->unique();

            $table->string('name_en');       // "Hearing Impaired – Light or Signal Required"
            $table->string('name_de');       // "Hörbehindert – Licht oder Signal erforderlich"

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

        // ── 2. Pivot Athlet → Exceptions ──────────────────────────────────────
        Schema::create('athlete_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('exception_code_id')
                ->constrained('exception_codes')
                ->cascadeOnDelete();

            // Für welche Sport-Kategorie gilt diese Exception beim Athleten?
            // null = allgemein (z.B. H, Y, A, T, B — nicht stilabhängig)
            // 'S', 'SB', 'SM' = nur für diese Kategorie
            $table->enum('category', ['S', 'SB', 'SM'])->nullable();

            // Optionale Notiz (z.B. Ablaufdatum der Klassifizierung, Bemerkung)
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // Pro Athlet + Code + Kategorie nur ein Eintrag
            $table->unique(['athlete_id', 'exception_code_id', 'category'], 'athlete_exception_unique');

            $table->index('athlete_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_exceptions');
        Schema::dropIfExists('exception_codes');
    }
};

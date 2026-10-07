<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prüfliste nach dem LENEX-Rekordimport (docs/specs/records.md "Prüfliste"):
 * - club_conflict: Verein laut Rekord weicht vom Stammverein des Athleten ab,
 * - year_match: unbekannter Athlet wurde einer Person mit abweichendem Geburtsdatum zugeordnet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_review_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignId('lenex_club_id')->nullable()->constrained('clubs')->nullOnDelete();
            $table->foreignId('swim_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->nullable();
            $table->json('details')->nullable();
            $table->string('status', 10)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index(['athlete_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_review_items');
    }
};

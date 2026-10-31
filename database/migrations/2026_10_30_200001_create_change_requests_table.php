<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anträge der Vereine (Spec club-permissions §4.5, Phase 6): Namensänderung und Ummeldung eines Athleten, entschieden
 * von einem Admin. "Ein offener Antrag je Art und Athlet" prüft die Anwendung (ein Index nur über offene Zeilen ist
 * nicht portabel). Benutzernamen als Kopie, damit sie nach dem Löschen des Benutzers sichtbar bleiben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
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

            $table->index(['status', 'created_at']);
            $table->index(['athlete_id', 'type', 'status']);
            $table->index(['club_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};

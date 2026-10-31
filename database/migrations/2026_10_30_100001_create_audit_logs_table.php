<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Änderungsprotokoll (Spec club-permissions §4.7, Phase 4): wer hat wann welchen Athleten, Verein, welche Meldung
 * oder Staffelmeldung angelegt, geändert oder gelöscht. Es wird nur geschrieben, nie geändert; aufbewahrt unbegrenzt
 * (P4-4). athlete_id/club_id dienen nur dem Filtern für den Verlauf, ohne Fremdschlüssel, damit Einträge gelöschter
 * Datensätze erhalten bleiben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
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

            $table->index(['auditable_type', 'auditable_id', 'created_at']);
            $table->index(['athlete_id', 'created_at']);
            $table->index(['club_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meldegelder nach LENEX: FEES > FEE an MEET (session_number leer) bzw. an SESSION (session_number gesetzt),
     * Typen CLUB/ATHLETE/RELAY/TEAM/LATEENTRY.INDIVIDUAL/LATEENTRY.RELAY. Die Gebühr je Meldung in einem Bewerb
     * (EVENT > FEE, ohne Typ) steht in swim_events.fee_cents. Beträge in Cent, wie LENEX FEE@value.
     */
    public function up(): void
    {
        Schema::create('meet_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('session_number')->nullable();
            $table->string('type', 30);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();

            $table->unique(['meet_id', 'session_number', 'type']);
        });

        Schema::table('swim_events', function (Blueprint $table) {
            $table->unsignedInteger('fee_cents')->nullable()->after('relay_count');
        });
    }

    public function down(): void
    {
        Schema::table('swim_events', function (Blueprint $table) {
            $table->dropColumn('fee_cents');
        });

        Schema::dropIfExists('meet_fees');
    }
};

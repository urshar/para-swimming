<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telefonnummer des Vereins (Kontaktdaten) und des Trainers (Spec club-permissions Phase 10b, P10b-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('contact_phone', 30)->nullable()->after('contact_email');
        });

        Schema::table('coaches', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('coaches', function (Blueprint $table) {
            $table->dropColumn('phone');
        });

        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('contact_phone');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Login eines Trainers (Spec club-permissions N7, P8-4): höchstens ein Benutzer je Trainer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('coach_id')->nullable()->unique()->after('club_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Fremdschlüssel vor dem Unique-Index lösen (MySQL-Fehler 1553, siehe CLAUDE.md).
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['coach_id']);
            $table->dropUnique(['coach_id']);
            $table->dropColumn('coach_id');
        });
    }
};

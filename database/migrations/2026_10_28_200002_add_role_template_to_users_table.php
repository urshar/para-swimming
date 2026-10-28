<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zuletzt angewendete Rechte-Vorlage (Spec club-permissions §4.1) — nur zur Information und für "Vorlage neu
 * anwenden"; maßgeblich sind die Zeilen in user_module_permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role_template', 30)->nullable()->after('club_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role_template');
        });
    }
};

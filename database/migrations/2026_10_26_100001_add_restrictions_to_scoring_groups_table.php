<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wertungsgruppen nach Landesverband und Level (docs/specs/scoring-group-restrictions.md).
 *
 * regional_associations: Verbandscodes (Club::REGIONAL_ASSOCIATIONS), kommagetrennt — leer = alle Vereine.
 * regional_mode: only = nur Vereine dieser Verbände, except = alle außer diesen (z. B. Gäste bei einer LM).
 * levels: Athleten-Level (Werte aus athletes.level, z. B. A/B/T), kommagetrennt — leer = alle Athleten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scoring_groups', function (Blueprint $table) {
            $table->string('regional_associations', 100)->nullable()->after('age_max');
            $table->string('regional_mode', 10)->nullable()->after('regional_associations');
            $table->string('levels', 100)->nullable()->after('regional_mode');
        });
    }

    public function down(): void
    {
        Schema::table('scoring_groups', function (Blueprint $table) {
            $table->dropColumn(['regional_associations', 'regional_mode', 'levels']);
        });
    }
};

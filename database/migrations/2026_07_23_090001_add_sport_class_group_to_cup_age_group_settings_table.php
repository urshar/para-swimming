<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Altersgruppen-Aktivierung wird von "cup-weit global" auf "pro
     * Sportklassengruppe" umgestellt (Erik, 2026-07-19), analog zu
     * CupGroupSetting::gender_combined, das bereits pro Sportklassengruppe
     * steuerbar ist.
     *
     * Reine Admin-Einstellungstabelle ohne Wettkampf-/Ergebnisdaten — daher
     * werden bestehende Einträge zurückgesetzt (Altersgruppen gelten danach
     * wieder als aktiv, bis pro Sportklassengruppe neu gesetzt wird), statt
     * eine mehrdeutige automatische Zuordnung zu bestehenden Gruppen zu
     * erraten.
     *
     * MySQL verlangt, dass Foreign Keys VOR dem zugehörigen Unique-Index
     * gelöscht werden, wenn dieser Index die Constraint stützt — daher
     * werden cup_id/age_group_id zuerst per dropForeign() gelöst, bevor
     * der alte Unique-Index entfernt wird, und danach wieder angelegt.
     */
    public function up(): void
    {
        Schema::table('cup_age_group_settings', function (Blueprint $table) {
            $table->dropForeign(['cup_id']);
            $table->dropForeign(['age_group_id']);
            $table->dropUnique('cup_age_group_unique');
        });

        DB::table('cup_age_group_settings')->delete();

        Schema::table('cup_age_group_settings', function (Blueprint $table) {
            $table->foreignId('sport_class_group_id')->after('cup_id')
                ->constrained()->cascadeOnDelete();

            $table->foreign('cup_id')->references('id')->on('cups')->cascadeOnDelete();
            $table->foreign('age_group_id')->references('id')->on('age_groups')->cascadeOnDelete();

            $table->unique(
                ['cup_id', 'sport_class_group_id', 'age_group_id'],
                'cup_age_group_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cup_age_group_settings', function (Blueprint $table) {
            $table->dropForeign(['cup_id']);
            $table->dropForeign(['age_group_id']);
            $table->dropForeign(['sport_class_group_id']);
            $table->dropUnique('cup_age_group_unique');
        });

        DB::table('cup_age_group_settings')->delete();

        Schema::table('cup_age_group_settings', function (Blueprint $table) {
            $table->dropColumn('sport_class_group_id');

            $table->foreign('cup_id')->references('id')->on('cups')->cascadeOnDelete();
            $table->foreign('age_group_id')->references('id')->on('age_groups')->cascadeOnDelete();

            $table->unique(['cup_id', 'age_group_id'], 'cup_age_group_unique');
        });
    }
};

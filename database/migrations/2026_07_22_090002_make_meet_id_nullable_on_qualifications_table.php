<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * qualifications wird nicht mehr zwingend an ein existierendes Meet
     * gebunden (Erik, 2026-07-18): die Qualifikationsermittlung findet oft
     * statt, bevor das Ziel-Meet des Folgejahres überhaupt angelegt wurde.
     * Primärer Anker ist jetzt qualifying_time_list_id (steht immer fest);
     * meet_id bleibt als optionale, später nachträglich pflegbare Referenz
     * bestehen (nullOnDelete statt cascadeOnDelete).
     *
     * Kein doctrine/dbal nötig: Spalte wird bewusst per drop + re-add statt
     * per ->change() migriert.
     */
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropForeign(['meet_id']);
            $table->dropUnique('qualifications_unique_entry');
        });

        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropColumn('meet_id');
        });

        Schema::table('qualifications', function (Blueprint $table) {
            $table->foreignId('meet_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();

            $table->unique(
                ['qualifying_time_list_id', 'athlete_id', 'qualifying_time_id'],
                'qualifications_unique_entry'
            );
        });
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropForeign(['meet_id']);
            $table->dropUnique('qualifications_unique_entry');
        });

        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropColumn('meet_id');
        });

        Schema::table('qualifications', function (Blueprint $table) {
            $table->foreignId('meet_id')->after('id')->constrained()->cascadeOnDelete();

            $table->unique(['meet_id', 'athlete_id', 'qualifying_time_id'], 'qualifications_unique_entry');
        });
    }
};

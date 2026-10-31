<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Top-Gruppe nach der Zwei-Jahres-Regel mit Historie und Hand-Einstufung
     * (Spec cup-top-group.md):
     *
     *   - reason wird ein String (App\Enums\TopGroupReason) statt eines Enums, weil die
     *     Regel mehr Gründe kennt (zwei Jahre drüber/drunter, Status übernommen).
     *   - calculated_is_top_group hält, was Kader bzw. Regel ergeben; is_top_group bleibt
     *     die wirksame Einstufung (Hand-Einstufung vor Regel), die die Tageswertung liest.
     *   - Vorjahresstatus und die letzten zwei Cup-Jahre mit Start machen Auf- und Abstieg
     *     nachvollziehbar; reference_points (bester Wert über zwei Jahre, alte Regel) entfällt.
     *   - manual_*: von Hand gesetzte Einstufung, die eine Neuberechnung nicht überschreibt.
     */
    public function up(): void
    {
        Schema::table('cup_top_group_classifications', function (Blueprint $table) {
            $table->string('reason', 20)->nullable()->change();
        });

        Schema::table('cup_top_group_classifications', function (Blueprint $table) {
            $table->dropColumn('reference_points');

            $table->boolean('calculated_is_top_group')->default(false)->after('is_top_group');
            $table->boolean('previous_is_top_group')->nullable()->after('reason');
            $table->unsignedSmallInteger('last_start_year')->nullable()->after('previous_is_top_group');
            $table->unsignedInteger('last_start_points')->nullable()->after('last_start_year');
            $table->unsignedSmallInteger('prior_start_year')->nullable()->after('last_start_points');
            $table->unsignedInteger('prior_start_points')->nullable()->after('prior_start_year');

            $table->boolean('manual_is_top_group')->nullable()->after('prior_start_points');
            $table->text('manual_note')->nullable()->after('manual_is_top_group');
            $table->foreignId('manual_set_by')->nullable()->after('manual_note')->constrained('users')->nullOnDelete();
            $table->timestamp('manual_set_at')->nullable()->after('manual_set_by');
        });

        DB::table('cup_top_group_classifications')->update(['calculated_is_top_group' => DB::raw('is_top_group')]);
    }

    public function down(): void
    {
        Schema::table('cup_top_group_classifications', function (Blueprint $table) {
            $table->dropForeign(['manual_set_by']);
            $table->dropColumn([
                'calculated_is_top_group', 'previous_is_top_group', 'last_start_year', 'last_start_points',
                'prior_start_year', 'prior_start_points', 'manual_is_top_group', 'manual_note', 'manual_set_by',
                'manual_set_at',
            ]);
            $table->unsignedInteger('reference_points')->nullable()->after('reason');
        });

        // Gründe der neuen Regel kennt das alte Enum nicht.
        DB::table('cup_top_group_classifications')
            ->whereNotIn('reason', ['KADER', 'POINTS_HISTORY'])
            ->update(['reason' => DB::raw("CASE WHEN is_top_group THEN 'POINTS_HISTORY' ELSE NULL END")]);

        Schema::table('cup_top_group_classifications', function (Blueprint $table) {
            $table->enum('reason', ['KADER', 'POINTS_HISTORY'])->nullable()->change();
        });
    }
};

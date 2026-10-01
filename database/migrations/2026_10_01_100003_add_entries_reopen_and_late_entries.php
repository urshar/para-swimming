<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meldeschluss kontrolliert wiedereröffnen (docs/specs/club-entries.md "Meldeschluss und Nachmeldungen"):
     *   - meets.entries_reopened_until: Vereine dürfen nach Meldeschluss bis zu diesem Zeitpunkt wieder melden,
     *   - meets.entries_reopened_by/_at: wer zuletzt wann wiedereröffnet hat (nur das letzte Öffnen),
     *   - entries/relay_entries.is_late_entry: nach Meldeschluss neu angelegt → Nachmeldegebühr (LATEENTRY.*).
     */
    public function up(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->timestamp('entries_reopened_until')->nullable()->after('entries_deadline');
            $table->foreignId('entries_reopened_by')->nullable()->after('entries_reopened_until')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('entries_reopened_at')->nullable()->after('entries_reopened_by');
        });

        Schema::table('entries', function (Blueprint $table) {
            $table->boolean('is_late_entry')->default(false)->after('status');
        });

        Schema::table('relay_entries', function (Blueprint $table) {
            $table->boolean('is_late_entry')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('relay_entries', function (Blueprint $table) {
            $table->dropColumn('is_late_entry');
        });

        Schema::table('entries', function (Blueprint $table) {
            $table->dropColumn('is_late_entry');
        });

        Schema::table('meets', function (Blueprint $table) {
            $table->dropForeign(['entries_reopened_by']);
            $table->dropColumn(['entries_reopened_until', 'entries_reopened_by', 'entries_reopened_at']);
        });
    }
};

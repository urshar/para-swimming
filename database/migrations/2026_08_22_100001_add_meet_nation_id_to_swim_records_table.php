<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Austragungsland des Wettkampfs, an dem der Rekord aufgestellt wurde (LENEX
     * MEETINFO@nation) — separat von swim_records.nation_id, das die für den Rekord
     * zuständige Nation trägt (im öffentlichen Bestand immer AUT, siehe
     * RecordImportService::import()). meet_city allein reichte nicht aus, um im
     * öffentlichen Rekordbrett eine Landesflagge neben dem Ort anzuzeigen — Rekorde
     * werden regelmäßig im Ausland aufgestellt (WM, EM, Paralympics).
     *
     * Nullable und ohne Backfill: historische Rekorde ohne importiertes MEETINFO@nation
     * bleiben ohne Flagge, bis sie neu importiert oder gepflegt werden.
     */
    public function up(): void
    {
        Schema::table('swim_records', function (Blueprint $table) {
            $table->foreignId('meet_nation_id')
                ->nullable()
                ->after('nation_id')
                ->constrained('nations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('swim_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meet_nation_id');
        });
    }
};

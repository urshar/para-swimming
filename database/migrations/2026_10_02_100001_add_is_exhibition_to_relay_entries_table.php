<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staffelmeldung außer Konkurrenz (AK). Einzelmeldungen nutzen dafür entries.status = 'EXH'; bei Staffeln ist
     * relay_entries.status der Ablaufstatus (pending/confirmed/withdrawn), daher ein eigenes Feld. Im LENEX-Export
     * als ENTRY status="EXH".
     */
    public function up(): void
    {
        Schema::table('relay_entries', function (Blueprint $table) {
            $table->boolean('is_exhibition')->default(false)->after('is_late_entry');
        });
    }

    public function down(): void
    {
        Schema::table('relay_entries', function (Blueprint $table) {
            $table->dropColumn('is_exhibition');
        });
    }
};

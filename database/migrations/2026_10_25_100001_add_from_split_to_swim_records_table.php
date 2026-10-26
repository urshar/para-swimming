<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Herkunft eines Rekords aus einer Zwischenzeit (z. B. 50 m im 100-m-Rennen, siehe docs/specs/split-records.md).
     * Ein solcher Rekord verweist wie gewohnt über result_id auf das Ergebnis, setzt aber kein Rekord-Flag daran —
     * die Flags gehören zur Endzeit des Bewerbs.
     */
    public function up(): void
    {
        Schema::table('swim_records', function (Blueprint $table) {
            $table->boolean('from_split')->default(false)->after('relay_count');
        });
    }

    public function down(): void
    {
        Schema::table('swim_records', function (Blueprint $table) {
            $table->dropColumn('from_split');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frei vergebbarer Staffelname. Leer = automatischer Name (Vereinsname, bei mehreren unbenannten
     * Staffeln desselben Vereins im selben Bewerb mit laufender Nummer) — siehe App\Support\RelayNames.
     */
    public function up(): void
    {
        Schema::table('relay_entries', function (Blueprint $table) {
            $table->string('name', 50)->nullable()->after('club_id');
        });
    }

    public function down(): void
    {
        Schema::table('relay_entries', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ordnet ein Meet optional genau einem Cup zu (Punkt 2 der Spec).
     * Ein Meet kann keinem oder genau einem Cup angehören — nie mehreren
     * gleichzeitig, daher reicht ein einfacher nullable Fremdschlüssel.
     */
    public function up(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->foreignId('cup_id')->nullable()->after('id')
                ->constrained('cups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cup_id');
        });
    }
};

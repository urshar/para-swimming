<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nach der email-Spalte einfügen
            $table->boolean('is_admin')->default(false)->after('email');
            $table->foreignId('club_id')
                ->nullable()
                ->after('is_admin')
                ->constrained('clubs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['club_id']);
            $table->dropColumn(['is_admin', 'club_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Öffentliche Sichtbarkeit und Livetiming-Verweis für Veranstaltungen
     * (Spec public-frontend §4.2).
     *
     * is_published default false, ausdrücklich auch für den Altbestand: Sonst wären mit dieser
     * Migration schlagartig alle bestehenden Wettkämpfe öffentlich sichtbar, einschließlich
     * Testdaten und noch nicht freigegebener Veranstaltungen.
     */
    public function up(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->string('livetiming_url')->nullable()->after('wps_approved_note');
            $table->boolean('is_published')->default(false)->after('livetiming_url');
        });
    }

    public function down(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->dropColumn(['livetiming_url', 'is_published']);
        });
    }
};

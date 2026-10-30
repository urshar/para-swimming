<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kontaktdaten des Vereins und eindeutiger Code je Nation (Spec club-permissions §4.6, N5, N8, Phase 7).
 * Vereine ohne Code (NULL) bleiben mehrfach möglich; gelöschte Vereine zählen mit (P7-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('swrid');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->string('address_street')->nullable()->after('contact_email');
            $table->string('address_zip', 20)->nullable()->after('address_street');
            $table->string('address_city')->nullable()->after('address_zip');
            $table->string('address_country', 3)->nullable()->after('address_city'); // ISO 3166-1 alpha-3

            $table->unique(['nation_id', 'code'], 'clubs_nation_code_unique');
        });
    }

    public function down(): void
    {
        // MySQL kann den Unique-Index als Index des Fremdschlüssels nation_id übernommen haben (Fehler 1553), daher
        // den Fremdschlüssel vorher lösen und danach wieder anlegen.
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropForeign(['nation_id']);
            $table->dropUnique('clubs_nation_code_unique');
        });

        Schema::table('clubs', function (Blueprint $table) {
            $table->foreign('nation_id')->references('id')->on('nations')->restrictOnDelete();
            $table->dropColumn([
                'contact_name', 'contact_email', 'address_street', 'address_zip', 'address_city', 'address_country',
            ]);
        });
    }
};

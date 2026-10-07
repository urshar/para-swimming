<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kärnten: Der Verbandscode wurde am 27.07.2026 von KLSV auf KBSV umgestellt (Club::REGIONAL_ASSOCIATIONS), die bis
 * dahin angelegten Regionalrekorde trugen aber weiter "AUT.KLSV" / "AUT.KLSV.JR". Sie fehlten dadurch im Verbandsfilter,
 * und neue Kärntner Rekorde hätten eine zweite Kette "AUT.KBSV" begonnen. Vor der Umbenennung gibt es keine
 * "AUT.KBSV"-Rekorde, es entstehen also keine doppelten Ketten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('swim_records')->where('record_type', 'AUT.KLSV')->update(['record_type' => 'AUT.KBSV']);
        DB::table('swim_records')->where('record_type', 'AUT.KLSV.JR')->update(['record_type' => 'AUT.KBSV.JR']);
    }

    public function down(): void
    {
        // Bewusst leer: Nach der Umbenennung lassen sich alte und neue Kärntner Rekorde nicht mehr unterscheiden, und
        // "AUT.KLSV" ist kein gültiger Typ mehr.
    }
};

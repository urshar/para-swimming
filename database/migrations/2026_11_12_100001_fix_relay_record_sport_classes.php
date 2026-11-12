<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Staffelrekorde aus dem LENEX-Rekordimport bekamen bei Brust- und Lagenstaffeln fälschlich die Klasse SB…/SM…
 * (z. B. SB49, SM34). Staffeln haben nur die Staffelklasse S14/S15/S20/S21/S34/S49 — der Rekordcheck legt sie
 * so an, die importierten bildeten dadurch eine eigene, nie überbotene Kette. Korrigiert die Klasse auf S….
 * Zeilenweise in PHP statt per String-Funktion in SQL (CONCAT/|| sind nicht portabel zwischen MySQL und SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('swim_records')
            ->where('relay_count', '>', 1)
            ->where(fn ($q) => $q->where('sport_class', 'like', 'SB%')->orWhere('sport_class', 'like', 'SM%'))
            ->get(['id', 'sport_class'])
            ->each(fn ($row) => DB::table('swim_records')->where('id', $row->id)
                ->update(['sport_class' => 'S'.substr($row->sport_class, 2)]));
    }

    public function down(): void
    {
        // Nicht umkehrbar: Die ursprüngliche (falsche) Klasse ist aus der Lage ableitbar, aber nicht gewollt.
    }
};

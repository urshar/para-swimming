<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * record_splits — Splitzeiten für Schwimmrekorde
     *
     * Analog zu result_splits (000009b), aber für swim_records.
     *
     * Laut LENEX 3.0: "split times are always saved continuously"
     * d.h. jede Split-Zeit ist die kumulierte Zeit ab Start.
     *
     * Beispiel 200m Freistil:
     *   distance=50   split_time=2832  → 28.32s nach 50m
     *   distance=100  split_time=5721  → 57.21s nach 100m
     *   distance=150  split_time=8645  → 1:26.45 nach 150m
     *   distance=200  split_time=11534 → 1:55.34 Endzeit
     *
     * Splits bleiben auch in der Historie erhalten — jeder swim_record
     * Eintrag (aktuell oder historisch) hat seine eigenen Splits.
     */
    public function up(): void
    {
        Schema::create('record_splits', function (Blueprint $table) {
            $table->id();

            // Verknüpfung zum Rekord — bei Löschen eines Rekords werden Splits mitgelöscht
            // Historische Rekorde bleiben in swim_records erhalten (is_current=false),
            // daher bleiben auch ihre Splits erhalten
            $table->foreignId('swim_record_id')
                ->constrained('swim_records')
                ->cascadeOnDelete();

            // Distanz in Metern an der die Split-Zeit gemessen wurde
            $table->integer('distance');

            // Kumulierte Zeit in Hundertstelsekunden
            $table->integer('split_time');

            $table->timestamps();

            // Pro Rekord nur eine Split-Zeit pro Distanz
            $table->unique(['swim_record_id', 'distance']);
            $table->index('swim_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_splits');
    }
};

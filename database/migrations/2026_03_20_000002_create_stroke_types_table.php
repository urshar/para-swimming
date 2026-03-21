<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stroke_types', function (Blueprint $table) {
            $table->id();

            // Interner Code — von uns vergeben, stable, unique
            $table->string('code', 20)->unique();

            // LENEX 3.0 stroke-Wert — exakt so wie im XML
            // Nicht unique: theoretisch könnten verschiedene Meetmanager
            // denselben Stroke-Code für unterschiedliche Dinge verwenden
            $table->string('lenex_code', 20);

            $table->string('name_de');   // Freistil, Rücken, Brust, Schmetterling …
            $table->string('name_en');   // Freestyle, Backstroke, Breaststroke, Butterfly …

            // Kategorie für Gruppierung in der Anwendung
            // standard = normales Schwimmen, fin = Flossenschwimmen
            $table->enum('category', ['standard', 'fin', 'special'])->default('standard');

            // Ist es ein Staffel-Stroke? (IMRELAY, MEDLEY wenn relaycount > 1)
            $table->boolean('is_relay_stroke')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('lenex_code');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stroke_types');
    }
};

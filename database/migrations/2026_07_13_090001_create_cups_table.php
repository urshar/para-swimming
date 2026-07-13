<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ÖBSV Cup — eine Konfiguration pro Kalenderjahr.
     *
     * Jedes Cup-Jahr ist unabhängig von den anderen. Änderungen an einem Cup
     * dürfen niemals Wertungen anderer Cup-Jahre beeinflussen (Historisierung).
     */
    public function up(): void
    {
        Schema::create('cups', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('year')->unique();
            $table->string('name', 150);

            // Verweist auf die bestehende ÖBSV-1000-Punkte-Tabelle (Wiederverwendung,
            // keine eigene Punkteberechnung im Cup-Modul).
            $table->foreignId('base_time_version_id')->constrained('base_time_versions');

            $table->unsignedTinyInteger('rounds_count')->default(1); // Anzahl Wertungsrunden
            $table->unsignedTinyInteger('best_of_count')->default(1); // beste X Tageswertungen für die Gesamtwertung

            // Punktgrenze, ab der ein Athlet automatisch in die Top-Gruppe fällt (Punkt 8 der Spec).
            $table->unsignedSmallInteger('top_group_points_threshold')->default(450);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cups');
    }
};

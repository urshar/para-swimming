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
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name', 40)->nullable();  // Kurzname z.B. "ÖBSV Wien"
            $table->string('code', 10)->nullable();        // Vereinskürzel z.B. "OEBSV"
            $table->foreignId('nation_id')->constrained()->restrictOnDelete();
            $table->string('type', 20)->default('CLUB');

            // LENEX: nicht unique — gleiche lenex_club_id kann von verschiedenen
            // Veranstaltungen vergeben werden (unterschiedliche Systeme, gleiche ID)
            // Matching beim Import erfolgt über name + nation_id oder manuell
            $table->string('lenex_club_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('nation_id');
            $table->index('lenex_club_id'); // Index ja, unique nein
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};

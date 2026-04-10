<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name',
                40)->nullable();  // LENEX CLUB.shortname (max. 20 Zeichen laut Spec, wir nehmen 40)
            $table->string('code', 10)->nullable();         // LENEX CLUB.code — offizieller Vereinscode
            $table->foreignId('nation_id')->constrained()->restrictOnDelete();

            // LENEX CLUB.type
            $table->enum('type', [
                'CLUB',
                'NATIONALTEAM',
                'REGIONALTEAM',
                'UNATTACHED',
                'VERBAND',
            ])->default('CLUB');

            $table->enum('regional_association', [
                'WBSV',
                'BBSV',
                'KLSV',
                'NOEVSV',
                'OOEBSV',
                'SBSV',
                'STBSV',
                'TBSV',
                'VBSV',
            ])->nullable();

            // LENEX CLUB.swrid — global unique ID von swimrankings.net
            // fehlt in früherer Version, aber in LENEX 3.0 für CLUB definiert
            $table->string('swrid')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('nation_id');
            $table->index('swrid');
            $table->index('regional_association');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};

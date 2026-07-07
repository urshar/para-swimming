<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_time_versions', function (Blueprint $table) {
            $table->id();

            // Bezeichnung der Version, z.B. "2021-2026", "2027 Update"
            $table->string('label', 100);

            // Gültigkeitszeitraum. valid_until = null bedeutet "bis auf Weiteres gültig".
            $table->date('valid_from');
            $table->date('valid_until')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_time_versions');
    }
};

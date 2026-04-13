<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relay_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('meet_id')
                ->constrained('meets')
                ->cascadeOnDelete();

            $table->foreignId('swim_event_id')
                ->constrained('swim_events')
                ->cascadeOnDelete();

            $table->foreignId('club_id')
                ->constrained('clubs')
                ->cascadeOnDelete();

            // Berechnete Staffelklasse (z.B. "S20", "S34", "S49", "S14", "S15", "S21")
            $table->string('relay_class', 10)->nullable();

            // Meldezeit in Millisekunden (analog zu entries.entry_time)
            $table->unsignedInteger('entry_time')->nullable();
            $table->string('entry_time_code', 10)->nullable(); // NT, A, B, etc.
            $table->string('entry_course', 3)->nullable();     // LCM / SCM

            // Status: pending / confirmed / withdrawn
            $table->string('status', 20)->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relay_entries');
    }
};

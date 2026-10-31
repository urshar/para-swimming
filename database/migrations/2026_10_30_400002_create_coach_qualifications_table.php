<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aus- und Fortbildungen eines Trainers (Spec club-permissions N6, P8-2): Die Stufe (ÜL, IN, TR) gibt es nur bei
 * Ausbildungen; die aktuelle Stufe des Trainers ist die höchste eingetragene Ausbildung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // education = Ausbildung, training = Fortbildung
            $table->string('level', 2)->nullable(); // App\Enums\CoachLevel, nur bei Ausbildung
            $table->string('title')->nullable();
            $table->date('completed_on');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['coach_id', 'completed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_qualifications');
    }
};

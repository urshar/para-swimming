<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_time_categories', function (Blueprint $table) {
            $table->id();

            // Interner Code, z.B. "LC_MEN", "SC_MIXED" — entspricht einem Excel-Arbeitsblatt.
            $table->string('code', 30)->unique();

            // Kurs (LCM/SCM) und Geschlecht (M/F/X) — Werte werden per Form Request validiert,
            // analog zum bestehenden Muster bei Meet/Result (course-Spalte ohne DB-Enum).
            $table->string('course', 3);
            $table->string('gender', 1);

            $table->string('label', 100);

            // Manche Kategorien (z.B. Mixed-Staffeln) übernehmen die Durchschnitts-Wachstumsfaktoren
            // einer anderen Kategorie (z.B. LC Mixed → LC Men), siehe base_time_derivation_rules.
            $table->foreignId('ratio_reference_category_id')
                ->nullable()
                ->constrained('base_time_categories')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_time_categories');
    }
};

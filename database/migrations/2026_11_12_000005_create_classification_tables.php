<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klassifizierer und Klassifizierungen der Athleten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classifiers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            // MED = Medizinisch, TECH = Technisch
            $table->enum('type', ['MED', 'TECH'])->index();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->foreignId('nation_id')->nullable()->index()->constrained('nations')->nullOnDelete();
            $table->enum('gender', ['M', 'F', 'N'])->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
        });

        Schema::create('athlete_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->index()->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('meet_id')->nullable()->constrained('meets')->cascadeOnDelete();
            // Die drei Klassifizierer des Ereignisses
            $table->foreignId('med_classifier_id')->nullable()->constrained('classifiers')->nullOnDelete();
            $table->foreignId('tech1_classifier_id')->nullable()->constrained('classifiers')->nullOnDelete();
            $table->foreignId('tech2_classifier_id')->nullable()->constrained('classifiers')->nullOnDelete();
            // Wann und wo
            $table->date('classified_at')->nullable()->index();
            $table->string('location')->nullable();
            // Ergebnis Sportklassen nach dieser Klassifikation (analog zu athlete_sport_classes)
            // jede Kategorie kann null sein, wenn der Athlet diese nicht schwimmt
            // z.B. "S4", "S14"
            $table->string('result_s', 15)->nullable();
            // z.B. "SB3", "SB14"
            $table->string('result_sb', 15)->nullable();
            // z.B. "SM4", "SM14"
            $table->string('result_sm', 15)->nullable();
            // Gültigkeitsbereich: INTL (mit SDMS) oder NAT (nur national)
            $table->enum('classification_scope', ['INTL', 'NAT'])->default('INTL');
            // Klassifikationsstatus
            $table->enum('classification_status', ['NEW', 'CONFIRMED', 'REVIEW', 'FRD', 'NE'])->nullable();
            // Nur bei FRD: das Jahr des nächsten Review-Termins
            $table->unsignedSmallInteger('frd_year')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('athlete_classification_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('athlete_classification_id');
            $table->unsignedBigInteger('exception_code_id');
            // Für welche Sport-Kategorie gilt die Exception (S / SB / SM)
            // null = allgemein (nicht stilabhängig: H, Y, A, T, B …)
            $table->enum('category', ['S', 'SB', 'SM'])->nullable();
            // Optionale Notiz (z.B. Ablaufdatum, Bemerkung)
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['athlete_classification_id'], 'ace_classification_idx');
            $table->unique(['athlete_classification_id', 'exception_code_id', 'category'], 'aclassif_exc_unique');

            $table->foreign('athlete_classification_id', 'ace_classification_fk')->references('id')->on('athlete_classifications')->cascadeOnDelete();
            $table->foreign('exception_code_id', 'ace_exception_code_fk')->references('id')->on('exception_codes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_classification_exceptions');
        Schema::dropIfExists('athlete_classifications');
        Schema::dropIfExists('classifiers');
    }
};

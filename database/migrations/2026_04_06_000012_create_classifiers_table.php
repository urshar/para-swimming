<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classifiers', function (Blueprint $table) {
            $table->id();

            $table->string('first_name');
            $table->string('last_name');
            $table->enum('type', ['MED', 'TECH']); // MED = Medizinisch, TECH = Technisch

            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->foreignId('nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->enum('gender', ['M', 'F', 'N'])->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
            $table->index('type');
            $table->index('nation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classifiers');
    }
};

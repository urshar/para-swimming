<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rechte je Benutzer und Modul (Spec club-permissions §4.1): Lese- und Schreibbereich jeweils "none", "own"
 * (eigener Verein) oder "all". Fehlende Zeile = kein Zugriff; Admins brauchen keine Zeilen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_module_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 40);
            $table->string('read_scope', 10)->default('none');
            $table->string('write_scope', 10)->default('none');
            $table->timestamps();

            $table->unique(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_module_permissions');
    }
};

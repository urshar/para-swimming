<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Behinderungsgruppe (PI/VI/MI/HI/T21) samt PI-Untergruppe ersetzt die freie Behinderungsart; dazu die Termine der
 * medizinischen Kontrolle (Übernahme aus dem Splash Team Manager).
 */
return new class extends Migration
{
    private const array TYPE_TO_GROUP = [
        'physical' => 'PI',
        'visual' => 'VI',
        'intellectual' => 'MI',
        'deaf' => 'HI',
        'trisomie' => 'T21',
    ];

    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->string('disability_group', 5)->nullable()->after('disability_type');
            $table->string('disability_subgroup', 1)->nullable()->after('disability_group');
            $table->date('last_medical_check_at')->nullable()->after('level');
            $table->date('next_medical_check_at')->nullable()->after('last_medical_check_at');
        });

        foreach (self::TYPE_TO_GROUP as $type => $group) {
            DB::table('athletes')->where('disability_type', $type)->update(['disability_group' => $group]);
        }

        Schema::table('athletes', function (Blueprint $table) {
            $table->dropColumn('disability_type');
            $table->index('disability_group');
        });
    }

    public function down(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->string('disability_type', 30)->nullable()->after('status');
        });

        foreach (self::TYPE_TO_GROUP as $type => $group) {
            DB::table('athletes')->where('disability_group', $group)->update(['disability_type' => $type]);
        }

        Schema::table('athletes', function (Blueprint $table) {
            $table->dropIndex(['disability_group']);
            $table->dropColumn(['disability_group', 'disability_subgroup', 'last_medical_check_at', 'next_medical_check_at']);
        });
    }
};

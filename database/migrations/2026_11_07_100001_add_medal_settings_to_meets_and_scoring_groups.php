<?php

use App\Models\ScoringGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Medaillenplanung (docs/specs/meet-entry-summary.md): Medaillenregel je Veranstaltung und Medaillenwertung je
 * Wertungsgruppe. Bestehende Gruppen bekommen fehlende Titel aus dem Namen (ÖSTM/ÖM/ÖJM/ÖBM); Medaillenwertung ja
 * genau dann, wenn ein Titel gesetzt ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meets', function (Blueprint $table) {
            $table->boolean('medal_reduction_rule')->default(true)->after('keep_file_places');
        });

        Schema::table('scoring_groups', function (Blueprint $table) {
            $table->boolean('awards_medals')->default(true)->after('title');
        });

        DB::table('scoring_groups')->orderBy('id')->chunkById(500, function ($groups) {
            foreach ($groups as $group) {
                $title = $group->title ?? ScoringGroup::titleFromName((string) $group->name);
                DB::table('scoring_groups')->where('id', $group->id)->update([
                    'title' => $title,
                    'awards_medals' => $title !== null,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Nachgetragene Titel bleiben stehen; sie sind auch ohne die neuen Spalten gültig.
        Schema::table('scoring_groups', function (Blueprint $table) {
            $table->dropColumn('awards_medals');
        });

        Schema::table('meets', function (Blueprint $table) {
            $table->dropColumn('medal_reduction_rule');
        });
    }
};

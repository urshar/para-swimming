<?php

use App\Models\Club;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('model-fillable-columns');

/**
 * Alle Eloquent-Models unter app/Models (inkl. Unterordner) als Klassennamen.
 *
 * @return array<int, class-string<Model>>
 */
function modelClasses_mfc(): array
{
    $dir = app_path('Models');

    return collect(File::allFiles($dir))
        ->map(fn (SplFileInfo $file) => 'App\\Models\\'.Str::of($file->getRelativePathname())
            ->replace(['/', '\\'], '\\')
            ->beforeLast('.php'))
        ->filter(fn (string $class) => class_exists($class)
            && is_subclass_of($class, Model::class)
            && ! (new ReflectionClass($class))->isAbstract())
        ->values()
        ->all();
}

/**
 * Regression für den Fall clubs.lenex_club_id: Ein $fillable-Feld ohne echte Spalte fällt zur Laufzeit nicht
 * auf — Eloquents __get() liefert für unbekannte Attribute still null, und erst ein tatsächliches Schreiben
 * scheitert auf MySQL mit "Unknown column". Deshalb hier für jedes Model gegen das echte Schema prüfen.
 */
it('hat für jedes fillable-Feld jedes Models eine echte Spalte', function () {
    $classes = modelClasses_mfc();
    $missing = [];

    foreach ($classes as $class) {
        $model = new $class;
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            $missing[] = "$class: Tabelle $table fehlt";

            continue;
        }

        foreach ($model->getFillable() as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $missing[] = "$class: $table.$column";
            }
        }
    }

    // Schutz gegen einen leeren Durchlauf (z. B. falsch aufgelöste Klassennamen).
    expect(count($classes))->toBeGreaterThan(20)
        ->and($classes)->toContain(Club::class)
        ->and($missing)->toBe([]);
});

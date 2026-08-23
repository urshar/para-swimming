<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Support\DocumentLocaleGroup;
use Illuminate\View\View;

/**
 * Public\RegulationController — Reglemente & Formulare (Spec public-frontend §5.5, Phase 8).
 *
 * Dokumente ganz ohne Veranstaltungsbezug (`documentable_type/id` = null, Phase 3 legt diese
 * bereits über den unpräfigierten Adminbereich an, §4.1). Bewusst auf die Kategorien REGULATION
 * und FORM eingeschränkt statt "gruppiert nach allen vorkommenden category-Werten": Das
 * Admin-Formular für Dokumente ohne Veranstaltungsbezug erlaubt zwar dieselben fünf Kategorien
 * wie das Veranstaltungsformular, aber INVITATION/START_LIST/RESULTS ergeben ohne Meet-Bezug
 * fachlich keinen Sinn — sie blieben dort nur mitwählbar, weil beide Formulare dieselbe Validierung
 * teilen (siehe Admin\DocumentController).
 */
class RegulationController extends Controller
{
    /**
     * Anzeigereihenfolge fest in PHP statt per orderBy('category') — ein enum sortiert in MySQL
     * nach Deklarationsreihenfolge, in SQLite (Testsuite) dagegen alphabetisch als Text
     * (CLAUDE.md: jede Query muss auf beiden Datenbanken laufen).
     */
    private const array CATEGORY_ORDER = ['REGULATION', 'FORM'];

    public function index(): View
    {
        $documents = Document::query()
            ->whereNull('documentable_type')
            ->whereIn('category', self::CATEGORY_ORDER)
            ->public()
            ->published()
            ->orderBy('sort_order')
            ->get();

        $groups = DocumentLocaleGroup::forDocuments($documents, app()->getLocale());

        $sections = collect(self::CATEGORY_ORDER)
            ->map(fn (string $category) => [
                'category' => $category,
                'groups' => $groups->filter(fn (DocumentLocaleGroup $group) => $group->document->category === $category)->values(),
            ])
            ->filter(fn (array $section) => $section['groups']->isNotEmpty())
            ->values();

        return view('public.regulations.index', ['sections' => $sections]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\Module;
use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeVersion;
use Illuminate\View\View;

/**
 * BaseTimeCategoryController
 *
 * Zeigt für eine Basiswert-Version die vorhandenen Kategorien (LC Men, SC Women, …)
 * als Kacheln an. Klick auf eine Kachel führt zur editierbaren Tabelle (Livewire).
 */
class BaseTimeCategoryController extends Controller
{
    public function index(BaseTimeVersion $version): View
    {
        $this->authorizeRead();

        $categories = BaseTimeCategory::query()
            ->whereHas('baseTimes', fn ($q) => $q->where('base_time_version_id', $version->id))
            ->withCount([
                'baseTimes as manual_count' => fn ($q) => $q->where('base_time_version_id', $version->id)
                    ->where('value_type', BaseTime::TYPE_MANUAL),
                'baseTimes as calculated_count' => fn ($q) => $q->where('base_time_version_id', $version->id)
                    ->where('value_type', BaseTime::TYPE_CALCULATED),
            ])
            ->orderBy('code')
            ->get();

        return view('base-times.categories.index', compact('version', 'categories'));
    }

    public function show(BaseTimeVersion $version, BaseTimeCategory $category): View
    {
        $this->authorizeRead();

        return view('base-times.categories.show', compact('version', 'category'));
    }

    private function authorizeRead(): void
    {
        abort_unless(auth()->user()?->canRead(Module::BaseTimes), 403, 'Kein Leserecht für Basiswerte.');
    }
}

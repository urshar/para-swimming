<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Services\Public\PublicRecordService;
use App\Support\PublicRecordFilter;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RecordController extends Controller
{
    public function __construct(
        private readonly PublicRecordService $records
    ) {}

    public function index(Request $request): View
    {
        $filter = PublicRecordFilter::fromQuery($request->query());

        return view('public.records.index', [
            'filter' => $filter,
            'groups' => $this->records->groupByStroke($this->records->forFilter($filter)),
            'sportClasses' => $this->records->availableSportClasses($filter->recordType()),
            'associations' => Club::REGIONAL_ASSOCIATIONS,
        ]);
    }
}

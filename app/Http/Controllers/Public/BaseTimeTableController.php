<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\PointConversionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BaseTimeTableController extends Controller
{
    private const array COURSES = ['LCM', 'SCM'];

    public function __construct(
        private readonly PointConversionService $points
    ) {}

    public function index(Request $request): View
    {
        $course = $request->query('course', 'SCM');
        if (! in_array($course, self::COURSES, true)) {
            $course = 'SCM';
        }

        $version = $this->points->currentVersion();

        return view('public.base-times.index', [
            'course' => $course,
            'version' => $version,
            'groups' => $version ? $this->points->buildTable($version, $course) : collect(),
        ]);
    }
}

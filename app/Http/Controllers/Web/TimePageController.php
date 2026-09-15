<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TimePageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Time/Index', [
            'timeEntries' => TimeEntry::with(['company', 'project', 'task'])
                ->orderByDesc('date')
                ->get(),
            'companies' => Company::orderBy('name')->get(['id', 'name', 'default_hourly_rate']),
            'projects' => Project::orderBy('name')->get(['id', 'company_id', 'name']),
        ]);
    }

    public function weekly(Request $request): Response
    {
        $start = Carbon::parse($request->query('week_start', now()->startOfWeek()->toDateString()))->startOfDay();
        $end = (clone $start)->endOfWeek();

        $entries = TimeEntry::with(['company', 'project', 'task'])
            ->whereBetween('date', [$start, $end])
            ->orderBy('date')
            ->get();

        return Inertia::render('Time/Weekly', [
            'weekStart' => $start->toDateString(),
            'weekEnd' => $end->toDateString(),
            'entries' => $entries,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }
}

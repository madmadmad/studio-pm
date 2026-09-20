<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        if ($request->user()->isTeamMember()) {
            return $this->teamMemberDashboard($request);
        }

        $invoices = Invoice::with(['items', 'company'])->latest()->get();

        $outstanding = $invoices->where('status', 'sent')->sum(fn (Invoice $invoice) => $invoice->total());
        $unbilledHours = (float) TimeEntry::where('billed', false)->sum('hours');
        $activeClients = Company::where('status', 'active')->count();

        $recentInvoices = $invoices->take(5)->map(fn (Invoice $invoice) => [
            'id' => $invoice->id,
            'company_name' => $invoice->company->name,
            'issued_on' => $invoice->issued_on,
            'due_on' => $invoice->due_on,
            'status' => $invoice->status,
            'total' => $invoice->total(),
        ]);

        return Inertia::render('Dashboard/Index', [
            'metrics' => [
                'outstanding' => $outstanding,
                'unbilled_hours' => $unbilledHours,
                'active_clients' => $activeClients,
            ],
            'recentInvoices' => $recentInvoices,
        ]);
    }

    // No firm financials for a Team Member -- just their own projects and hours.
    protected function teamMemberDashboard(Request $request): Response
    {
        $projects = $request->user()->activeProjects()->with('company')->orderBy('name')->get();

        $weekHours = (float) TimeEntry::where('user_id', $request->user()->id)
            ->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('hours');

        return Inertia::render('Dashboard/TeamMember', [
            'projects' => $projects,
            'weekHours' => $weekHours,
        ]);
    }
}

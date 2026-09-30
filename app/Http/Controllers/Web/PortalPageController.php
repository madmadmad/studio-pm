<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Project;
use App\Policies\Portal\ProjectPolicy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortalPageController extends Controller
{
    public function __construct(protected ProjectPolicy $policy) {}

    // The Client Hub's sections -- Projects (home), Proposals, Invoices,
    // Contacts -- each its own page in the shared sidebar. Every page sends
    // an explicit list of fields: Inertia sends every prop to the browser,
    // so nothing internal (drafts, budgets, rates, reminder settings, other
    // clients) can be left to the UI to hide. The company always comes
    // from the signed-in contact, never the URL.

    // Active work, plus anything waiting on the client to accept a
    // proposal (sent, not yet accepted).
    public function index(Request $request): Response
    {
        $projects = $request->user()->visibleProjects()
            ->where(fn ($query) => $query
                ->where('status', 'active')
                ->orWhereHas('proposals', fn ($q) => $q->where('status', 'sent')))
            ->with(['tasks' => fn ($q) => $q->visibleToClient()->select('id', 'project_id', 'status')])
            ->orderBy('name')
            ->get()
            ->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'tasks' => $project->tasks->map->only('status')->values(),
            ]);

        return Inertia::render('Portal/Projects/Index', [
            'projects' => $projects,
        ]);
    }

    public function proposals(Request $request): Response
    {
        $proposals = $request->user()->company->proposals()
            ->whereIn('status', ['sent', 'accepted'])
            ->with('project:id,name')
            ->latest()
            ->get()
            ->map(fn ($proposal) => [
                'id' => $proposal->id,
                'title' => $proposal->title,
                'status' => $proposal->status,
                'estimate_amount' => $proposal->estimate_amount,
                'accept_token' => $proposal->accept_token,
                'project' => $proposal->project?->only('name'),
            ]);

        return Inertia::render('Portal/Proposals/Index', [
            'proposals' => $proposals,
        ]);
    }

    // Billing and primary contacts only.
    public function invoices(Request $request): Response
    {
        abort_unless($request->user()->canViewInvoices(), 403);

        $invoices = $request->user()->company->invoices()
            ->whereIn('status', ['sent', 'paid'])
            ->with(['items', 'payments', 'project:id,name'])
            ->orderByDesc('issued_on')
            ->orderByDesc('invoice_number')
            ->get()
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'issued_on' => $invoice->issued_on,
                'due_on' => $invoice->due_on,
                'total' => $invoice->total(),
                'balance' => $invoice->remainingBalance(),
                'public_token' => $invoice->public_token,
                'project' => $invoice->project?->only('name'),
            ]);

        return Inertia::render('Portal/Invoices/Index', [
            'invoices' => $invoices,
        ]);
    }

    // The company's details and its people.
    public function contacts(Request $request): Response
    {
        $company = $request->user()->company;

        $contacts = $company->contacts()
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'role' => $c->role,
                'email' => $c->email,
                'phone' => $c->phone,
                'is_primary' => $c->is_primary,
                'is_billing' => $c->is_billing,
                'avatar_url' => $c->avatar_url,
            ]);

        return Inertia::render('Portal/Contacts/Index', [
            'company' => $company->only('name', 'phone', 'address_line1', 'city', 'state', 'postal_code'),
            'contacts' => $contacts,
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        abort_unless($this->policy->view($request->user(), $project), 403);

        $project->load([
            'company.contacts',
            // Only tasks set to "Show client".
            'tasks' => fn ($q) => $q->visibleToClient()->with('subtasks', 'files'),
            // Only threads this contact was included on.
            'messages' => fn ($q) => $q->includingContact($request->user())->withTrashed()->with(Message::threadRelations()),
            'activeUsers:id,name,role,avatar_path',
            'scheduleItems:id,project_id,title,description,starts_on,ends_on,position',
            'proposals' => fn ($q) => $q->where('status', 'accepted')->with('items'),
            'invoices' => fn ($q) => $request->user()->canViewInvoices()
                ? $q->whereIn('status', ['sent', 'paid'])->with('items')
                // Same rule as the home page: billing and primary contacts only.
                : $q->whereRaw('1 = 0'),
        ]);

        return Inertia::render('Portal/Projects/Show', [
            'project' => $project,
            'canViewInvoices' => $request->user()->canViewInvoices(),
        ]);
    }
}

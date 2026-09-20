<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Policies\Portal\ProjectPolicy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortalPageController extends Controller
{
    public function __construct(protected ProjectPolicy $policy) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Portal/Projects/Index', [
            'projects' => $request->user()->visibleProjects()->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        abort_unless($this->policy->view($request->user(), $project), 403);

        $project->load([
            'company',
            'tasks.subtasks',
            'tasks.files',
            'messages' => fn ($q) => $q->with(['replies.senderUser', 'replies.senderContact', 'senderUser', 'senderContact']),
            'activeUsers:id,name,role',
            'proposals' => fn ($q) => $q->where('status', 'accepted')->with('items'),
            'invoices' => fn ($q) => $q->whereIn('status', ['sent', 'paid'])->with('items'),
        ]);

        return Inertia::render('Portal/Projects/Show', [
            'project' => $project,
        ]);
    }
}

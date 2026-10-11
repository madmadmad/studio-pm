<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_projects')]
#[Title('List projects')]
#[Description('Lists the studio projects you work on (all projects if you have the All projects permission), newest first, with their client, status and number of open tasks. Use it to find a project ID before getting a project, listing or creating its tasks, or logging time. Archived projects are left out unless you ask for status "archived".')]
#[IsReadOnly]
#[IsIdempotent]
class ListProjectsTool extends StudioTool
{
    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:leads,estimated,active,inactive,completed,archived'],
            'company_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $projects = Project::visibleTo($this->actor($request))
            ->with('company:id,name')
            ->withCount(['tasks as open_tasks' => fn ($q) => $q->where('status', '!=', 'done')])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->latest()
            ->limit($data['limit'] ?? 50)
            ->get();

        return Response::structured([
            'count' => $projects->count(),
            'projects' => $projects->map(fn (Project $p) => [...$this->projectSummary($p), 'open_tasks' => $p->open_tasks])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['leads', 'estimated', 'active', 'inactive', 'completed', 'archived'])
                ->description('Only projects with this status. Leads → Estimated (proposal sent) → Active (proposal accepted).'),
            'company_id' => $schema->integer()->description('Only this client\'s projects (an ID from list_companies).'),
            'search' => $schema->string()->description('Part of the project name.'),
            'limit' => $schema->integer()->description('At most this many (1–100, default 50).'),
        ];
    }
}

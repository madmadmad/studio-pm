<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_project')]
#[Title('Get a project')]
#[Description('Gets one project in detail: its client and contact, description, budget, current team, open tasks, unbilled hours, and (with the Proposals permission) its proposals. Use it to understand a project before adding tasks, logging time or drafting a proposal.')]
#[IsReadOnly]
#[IsIdempotent]
class GetProjectTool extends StudioTool
{
    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate(['project_id' => ['required', 'integer', 'exists:projects,id']]);
        $user = $this->actor($request);

        $project = Project::with(['company:id,name', 'contact:id,name,email', 'activeUsers:id,name'])->findOrFail($data['project_id']);
        Gate::forUser($user)->authorize('view', $project);

        $openTasks = $project->tasks()->with('subtasks')->where('status', '!=', 'done')->orderBy('due_date')->orderBy('id')->limit(50)->get()->each->setRelation('project', $project);
        $proposals = $user->hasPermission('proposals')
            ? $project->proposals()->latest()->get(['id', 'title', 'status', 'estimate_amount'])->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'status' => $p->status,
                'estimate' => $p->estimate_amount !== null ? (float) $p->estimate_amount : null,
                'url' => $this->link("/proposals/{$p->id}/edit"),
            ])->all()
            : null;

        return Response::structured([
            ...$this->projectSummary($project),
            'description' => $project->description,
            'po_number' => $project->po_number,
            'budget' => $project->budget !== null ? (float) $project->budget : null,
            'contact' => $project->contact?->only('id', 'name', 'email'),
            'team' => $project->activeUsers->map->only('id', 'name')->values()->all(),
            'open_tasks' => $openTasks->map(fn ($t) => $this->taskSummary($t))->all(),
            'unbilled_hours' => round((float) TimeEntry::unbilled()->where('project_id', $project->id)->sum('hours'), 2),
            'proposals' => $proposals,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project\'s ID (from list_projects).')->required(),
        ];
    }
}

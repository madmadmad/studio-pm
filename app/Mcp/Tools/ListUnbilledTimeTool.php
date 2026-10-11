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

#[Name('list_unbilled_time')]
#[Title('List unbilled time')]
#[Description('Lists billable time that isn\'t on an invoice yet, on the projects you can see, newest first — with total hours overall and per project. Filter by project, client, person or date range. Use it to see what\'s ready to bill or to check what was logged.')]
#[IsReadOnly]
#[IsIdempotent]
class ListUnbilledTimeTool extends StudioTool
{
    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $user = $this->actor($request);

        if (! empty($data['project_id'])) {
            Gate::forUser($user)->authorize('view', Project::findOrFail($data['project_id']));
        }

        $query = TimeEntry::unbilled()
            ->visibleTo($user)
            ->when($data['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($data['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('date', '>=', $date))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('date', '<=', $date));

        // Totals over everything that matches, not just the entries listed.
        $byProject = (clone $query)
            ->selectRaw('project_id, sum(hours) as hours')
            ->groupBy('project_id')
            ->get()
            ->map(fn ($row) => ['project_id' => $row->project_id, 'hours' => round((float) $row->hours, 2)]);
        $names = Project::whereIn('id', $byProject->pluck('project_id'))->pluck('name', 'id');

        $entries = $query->with(['user:id,name', 'project:id,name', 'task:id,title', 'service:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($data['limit'] ?? 200)
            ->get();

        return Response::structured([
            'total_hours' => round($byProject->sum('hours'), 2),
            'by_project' => $byProject->map(fn ($row) => [
                ...$row,
                'name' => $names[$row['project_id']] ?? null,
                'url' => $this->link("/projects/{$row['project_id']}?tab=Time"),
            ])->sortByDesc('hours')->values()->all(),
            'count' => $entries->count(),
            'entries' => $entries->map(fn (TimeEntry $e) => $this->timeEntrySummary($e))->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Only this project\'s time (an ID from list_projects).'),
            'company_id' => $schema->integer()->description('Only time for this client (an ID from list_companies).'),
            'user_id' => $schema->integer()->description('Only this person\'s time (a staff member\'s user ID).'),
            'from' => $schema->string()->description('On or after this date (YYYY-MM-DD).'),
            'to' => $schema->string()->description('On or before this date (YYYY-MM-DD).'),
            'limit' => $schema->integer()->description('List at most this many entries (1–500, default 200); the totals always cover every match.'),
        ];
    }
}

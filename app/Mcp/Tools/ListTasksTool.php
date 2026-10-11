<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Task;
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

#[Name('list_tasks')]
#[Title('List tasks')]
#[Description('Lists tasks on the projects you can see — by default the open ones (to do and in progress), soonest due first. Filter by project, status, assignee or due date. Each task includes its subtasks. Use it to find a task or subtask ID before update_task or update_subtask, or to answer "what\'s on my plate".')]
#[IsReadOnly]
#[IsIdempotent]
class ListTasksTool extends StudioTool
{
    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'status' => ['nullable', 'in:open,todo,in_progress,done,all'],
            'assignee' => ['nullable', 'string', 'max:255'],
            'due_before' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $user = $this->actor($request);

        if (! empty($data['project_id'])) {
            Gate::forUser($user)->authorize('view', Project::findOrFail($data['project_id']));
        }

        $status = $data['status'] ?? 'open';
        $tasks = Task::with('project:id,name', 'subtasks')
            ->whereHas('project', fn ($q) => $q->visibleTo($user, current: false))
            ->when($data['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->when($status === 'open', fn ($q) => $q->where('status', '!=', 'done'))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($data['assignee'] ?? null, fn ($q, $name) => $q->where('assignee', 'like', "%{$name}%"))
            ->when($data['due_before'] ?? null, fn ($q, $date) => $q->whereDate('due_date', '<=', $date))
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit($data['limit'] ?? 100)
            ->get();

        return Response::structured([
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn (Task $t) => $this->taskSummary($t))->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Only this project\'s tasks (an ID from list_projects).'),
            'status' => $schema->string()->enum(['open', 'todo', 'in_progress', 'done', 'all'])
                ->description('"open" (default) is to do and in progress together; "all" includes done.'),
            'assignee' => $schema->string()->description('Part of the assignee\'s name (tasks are assigned by name).'),
            'due_before' => $schema->string()->description('Only tasks due on or before this date (YYYY-MM-DD).'),
            'limit' => $schema->integer()->description('At most this many (1–200, default 100).'),
        ];
    }
}

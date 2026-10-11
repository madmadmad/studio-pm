<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateTask;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_task')]
#[Title('Update a task')]
#[Description('Changes a task — its status (todo, in_progress, done), title, description, assignee, due date, or whether the client sees it. Only the fields you give change; send null to clear the description, assignee or due date. You need to be on the project\'s team. Returns the updated task with a link.')]
#[IsIdempotent]
class UpdateTaskTool extends StudioTool
{
    private const FIELDS = ['title', 'assignee', 'description', 'status', 'due_date', 'visible_to_client'];

    public function handle(Request $request, UpdateTask $updateTask): ResponseFactory
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            ...UpdateTask::rules(),
        ]);

        $changes = collect($data)->only(self::FIELDS)->all();
        if ($changes === []) {
            throw ValidationException::withMessages(['task_id' => 'Give at least one thing to change: '.implode(', ', self::FIELDS).'.']);
        }

        $task = $updateTask->handle($this->actor($request), Task::findOrFail($data['task_id']), $changes);

        return Response::structured(['updated' => true, 'task' => $this->taskSummary($task->load('project:id,name'))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task to change (an ID from list_tasks or get_project).')->required(),
            'status' => $schema->string()->enum(['todo', 'in_progress', 'done'])->description('Its new status.'),
            'title' => $schema->string()->description('A new title.'),
            'description' => $schema->string()->nullable()->description('New detail (null clears it).'),
            'assignee' => $schema->string()->nullable()->description('Who it\'s for, by name (null clears it).'),
            'due_date' => $schema->string()->nullable()->description('New due date, YYYY-MM-DD (null clears it).'),
            'visible_to_client' => $schema->boolean()->description('Show it to the client in their portal.'),
        ];
    }
}

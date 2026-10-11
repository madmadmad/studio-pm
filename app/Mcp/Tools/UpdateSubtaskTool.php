<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateSubtask;
use App\Models\Subtask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_subtask')]
#[Title('Update a subtask')]
#[Description('Changes one subtask — tick it off (status "done"), set it in progress, rename it, or reassign it. Only the fields you give change. You need to be on the task\'s project team. Returns its task with all its subtasks.')]
#[IsIdempotent]
class UpdateSubtaskTool extends StudioTool
{
    private const FIELDS = ['title', 'assignee', 'status'];

    public function handle(Request $request, UpdateSubtask $updateSubtask): ResponseFactory
    {
        $data = $request->validate([
            'subtask_id' => ['required', 'integer', 'exists:subtasks,id'],
            ...collect(UpdateSubtask::rules())->only(self::FIELDS)->all(),
        ]);

        $changes = collect($data)->only(self::FIELDS)->all();
        if ($changes === []) {
            throw ValidationException::withMessages(['subtask_id' => 'Give at least one thing to change: '.implode(', ', self::FIELDS).'.']);
        }

        $subtask = $updateSubtask->handle($this->actor($request), Subtask::findOrFail($data['subtask_id']), $changes);

        return Response::structured([
            'updated' => true,
            'subtask_id' => $subtask->id,
            'task' => $this->taskSummary($subtask->task->load('project:id,name', 'subtasks')),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'subtask_id' => $schema->integer()->description('The subtask to change (an ID from a task\'s subtasks in list_tasks or get_project).')->required(),
            'status' => $schema->string()->enum(['todo', 'in_progress', 'done'])->description('Its new status; "done" ticks it off.'),
            'title' => $schema->string()->description('A new title.'),
            'assignee' => $schema->string()->nullable()->description('Who it\'s for, by name (null clears it).'),
        ];
    }
}

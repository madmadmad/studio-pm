<?php

namespace App\Mcp\Tools;

use App\Actions\CreateSubtask;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('create_subtask')]
#[Title('Add subtasks to a task')]
#[Description('Adds one or more subtasks (a checklist) to the end of an existing task\'s list, each starting as "to do". You need to be on the task\'s project team. To make a new task with subtasks in one go, use create_task with its subtasks list instead. Returns the task with all its subtasks and their IDs.')]
class CreateSubtaskTool extends StudioTool
{
    public function handle(Request $request, CreateSubtask $createSubtask): ResponseFactory
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            'subtasks' => ['required', 'array', 'min:1', 'max:50'],
            ...collect(CreateSubtask::rules())->mapWithKeys(fn ($rules, $field) => ["subtasks.*.{$field}" => $rules])->all(),
        ]);

        $task = Task::findOrFail($data['task_id']);
        foreach ($data['subtasks'] as $subtask) {
            $createSubtask->handle($this->actor($request), $task, $subtask);
        }

        return Response::structured([
            'created' => count($data['subtasks']),
            'task' => $this->taskSummary($task->load('project:id,name', 'subtasks')),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task to add them to (an ID from list_tasks or get_project).')->required(),
            'subtasks' => $schema->array()->items($schema->object([
                'title' => $schema->string()->description('What needs doing.')->required(),
                'assignee' => $schema->string()->description('Who it\'s for, by name.'),
            ]))->description('The subtasks, in order.')->required(),
        ];
    }
}

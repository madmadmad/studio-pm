<?php

namespace App\Mcp\Tools;

use App\Actions\CreateTask;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('create_task')]
#[Title('Create a task')]
#[Description('Adds a task to a project, as you — you need to be on the project\'s team (or have the All projects permission). Starts as "to do". Tasks are assigned by name, as free text. Returns the new task with its ID and a link.')]
class CreateTaskTool extends StudioTool
{
    public function handle(Request $request, CreateTask $createTask): ResponseFactory
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            ...CreateTask::rules(),
        ]);

        $project = Project::findOrFail($data['project_id']);
        $task = $createTask->handle($this->actor($request), $project, collect($data)->except('project_id')->all());

        // Fresh, for the database's defaults (status "todo").
        return Response::structured(['created' => true, 'task' => $this->taskSummary($task->refresh()->load('project:id,name'))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project to add it to (an ID from list_projects).')->required(),
            'title' => $schema->string()->description('What needs doing, in a few words.')->required(),
            'description' => $schema->string()->description('More detail, if any.'),
            'assignee' => $schema->string()->description('Who it\'s for, by name.'),
            'due_date' => $schema->string()->description('When it\'s due (YYYY-MM-DD).'),
            'visible_to_client' => $schema->boolean()->description('Show it to the client in their portal (default false).'),
        ];
    }
}

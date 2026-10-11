<?php

namespace App\Mcp\Tools;

use App\Actions\LogTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('log_time')]
#[Title('Log time')]
#[Description('Logs hours you worked on a project (at least 0.25), optionally against one of its tasks and a service — the service decides whether it\'s billable, as in the app. The date defaults to today. It\'s logged as you; only super admins may log it for someone else (user_id), who must be on the project\'s team. Returns the entry with its ID and a link.')]
class LogTimeTool extends StudioTool
{
    public function handle(Request $request, LogTime $logTime): ResponseFactory
    {
        $user = $this->actor($request);
        $input = $request->all();
        $input['date'] ??= today()->toDateString();

        $data = Validator::make($input, LogTime::rules($input['project_id'] ?? null))->validate();

        // The app lets anyone with Manage projects log for someone else;
        // through Claude, only super admins.
        if (isset($data['user_id']) && (int) $data['user_id'] !== $user->id && ! $user->isSuperAdmin()) {
            throw new AuthorizationException('Only super admins can log time for someone else here.');
        }

        $entry = $logTime->handle($user, $data)->load('user:id,name', 'project:id,name', 'task:id,title', 'service:id,name');

        return Response::structured(['logged' => true, 'time_entry' => $this->timeEntrySummary($entry)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('The project the time was on (an ID from list_projects).')->required(),
            'hours' => $schema->number()->description('Hours worked, at least 0.25 (e.g. 1.5).')->required(),
            'date' => $schema->string()->description('The day it was worked (YYYY-MM-DD). Defaults to today.'),
            'note' => $schema->string()->description('What was done.'),
            'task_id' => $schema->integer()->description('One of the project\'s tasks it was for (from list_tasks).'),
            'service_id' => $schema->integer()->description('The service it was for; sets whether it\'s billable.'),
            'user_id' => $schema->integer()->description('Super admins only: whose time it is, if not yours.'),
        ];
    }
}

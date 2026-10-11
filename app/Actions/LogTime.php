<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Service;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Logging time inside a project, against one of its team -- the time
// screens (TimeEntryController) and the MCP server's log_time tool. A
// manager can log for anyone currently on the project (themselves by
// default); a team member always logs their own.
class LogTime
{
    public static function rules(mixed $projectId): array
    {
        return [
            'project_id' => ['required', 'exists:projects,id'],
            'task_id' => ['nullable', Rule::exists('tasks', 'id')->where('project_id', $projectId)],
            'user_id' => ['nullable', 'exists:users,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.25'],
            'note' => ['nullable', 'string'],
        ];
    }

    // `$data` is validated against rules().
    public function handle(User $actor, array $data): TimeEntry
    {
        $project = Project::findOrFail($data['project_id']);
        Gate::forUser($actor)->authorize('create', [TimeEntry::class, $project]);

        $data['company_id'] = $project->company_id;
        $data['user_id'] = self::teamMemberFor($actor, $project, $data['user_id'] ?? null);

        return TimeEntry::create(self::withServiceBillable($data));
    }

    // Whose time an entry is. A team member can only put their own name on
    // it; a manager can pick anyone currently on the project.
    public static function teamMemberFor(User $actor, Project $project, ?int $userId): int
    {
        $userId ??= $actor->id;

        if ($userId !== $actor->id) {
            if (! $actor->hasPermission('manage_projects')) {
                throw new AuthorizationException('Logging time for someone else takes the Manage projects permission.');
            }
            if (! $project->currentlyHasUser(User::findOrFail($userId))) {
                throw ValidationException::withMessages(['user_id' => "That person isn't on this project's team."]);
            }
        }

        return $userId;
    }

    // An entry for a service takes the service's billable setting (an
    // explicit billable in the same request still wins, as an override).
    public static function withServiceBillable(array $data): array
    {
        if (! empty($data['service_id']) && ! array_key_exists('billable', $data)) {
            $data['billable'] = Service::findOrFail($data['service_id'])->billable;
        }

        return $data;
    }
}

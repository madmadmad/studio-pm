<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

// What the studio's MCP tools share: who's asking (the Passport token's
// staff member -- routes/ai.php, EnsureMcpUser), links back into the app,
// and the same short shapes for a project, task or time entry wherever
// one turns up in a result.
abstract class StudioTool extends Tool
{
    protected function actor(Request $request): User
    {
        return $request->user();
    }

    protected function link(string $path): string
    {
        return url($path);
    }

    protected function projectSummary(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'company' => $project->company ? ['id' => $project->company->id, 'name' => $project->company->name] : null,
            'url' => $this->link("/projects/{$project->id}"),
        ];
    }

    protected function taskSummary(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'assignee' => $task->assignee,
            'due_date' => $task->due_date?->toDateString(),
            'visible_to_client' => (bool) $task->visible_to_client,
            'project' => $task->project ? ['id' => $task->project->id, 'name' => $task->project->name] : null,
            // Tasks open in a drawer on their project's Tasks tab.
            'url' => $this->link("/projects/{$task->project_id}?tab=Tasks"),
        ];
    }

    protected function timeEntrySummary(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->date?->toDateString(),
            'hours' => (float) $entry->hours,
            'note' => $entry->note,
            'billable' => (bool) $entry->billable,
            'billed' => (bool) $entry->billed,
            'person' => $entry->user ? ['id' => $entry->user->id, 'name' => $entry->user->name] : null,
            'project' => $entry->project ? ['id' => $entry->project->id, 'name' => $entry->project->name] : null,
            'task' => $entry->task ? ['id' => $entry->task->id, 'title' => $entry->task->title] : null,
            'service' => $entry->service ? ['id' => $entry->service->id, 'name' => $entry->service->name] : null,
            'url' => $this->link("/projects/{$entry->project_id}?tab=Time"),
        ];
    }

    // Text from the client (Markdown or plain) as the rich text the
    // proposal editor saves: Markdown rendered with any raw HTML stripped,
    // then cut down to the editor's own tags with no attributes.
    protected function richText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return RichText::toSafeHtml(Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }
}

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Message;
use App\Models\Note;
use App\Models\Payment;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskFile;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// The Overview's latest activity, newest first, gathered from what the
// records already keep (no separate log): tasks added and completed,
// messages and replies, notes, task files, logged time and accepted
// proposals -- and, by permission, proposals sent (Proposals) and invoices
// sent and payments (Invoices). Logged time is rolled up by person, project
// and day. Without All projects, only their assigned projects.
//
// Every source works on plain collections (->toBase()): mapping an empty
// Eloquent collection keeps it Eloquent, whose merge() then chokes on the
// other source's arrays.
//
// Each event: type, at, actor, text, subject (the quoted thing), project
// { id, name } or null, href.
class ActivityFeed
{
    public static function for(User $user, int $limit = 15): array
    {
        $projectIds = $user->hasPermission('all_projects') ? null : $user->activeProjects()->pluck('projects.id')->all();
        $scope = fn ($query, string $column = 'project_id') => $projectIds === null ? $query : $query->whereIn($column, $projectIds);

        $events = collect()
            ->merge(static::tasks($scope, $limit))
            ->merge(static::messages($scope, $limit))
            ->merge(static::notes($scope, $limit))
            ->merge(static::files($projectIds, $limit))
            ->merge(static::time($scope, $limit))
            // Everyone on a project sees its approved proposals.
            ->merge(static::proposalsAccepted($scope, $limit));

        if ($user->hasPermission('proposals')) {
            $events = $events->merge(static::proposalsSent($scope, $limit));
        }
        if ($user->hasPermission('invoices')) {
            $events = $events
                ->merge(static::invoices($scope, $limit))
                ->merge(static::payments($projectIds, $limit));
        }

        return $events->sortByDesc('at')->take($limit)->values()
            ->map(fn (array $e) => [...$e, 'at' => $e['at']->toIso8601String()])
            ->all();
    }

    private static function event(string $type, Carbon $at, ?string $actor, string $text, ?string $subject, $project, string $href, ?float $amount = null): array
    {
        return [
            'type' => $type,
            'at' => $at,
            'actor' => $actor,
            'text' => $text,
            'subject' => $subject,
            'project' => $project ? ['id' => $project->id, 'name' => $project->name] : null,
            'href' => $href,
            'amount' => $amount,
        ];
    }

    private static function projectHref($projectId, string $tab): string
    {
        return "/projects/{$projectId}?tab={$tab}";
    }

    private static function tasks($scope, int $limit): Collection
    {
        $added = $scope(Task::with('project:id,name'))->latest()->limit($limit)->get()->toBase()
            ->map(fn (Task $t) => static::event('task_added', $t->created_at, null, 'New task', $t->title, $t->project, static::projectHref($t->project_id, 'Tasks')));

        // Completed: a done task's last change is when it was ticked off.
        $done = $scope(Task::with('project:id,name'))->where('status', 'done')->latest('updated_at')->limit($limit)->get()->toBase()
            ->map(fn (Task $t) => static::event('task_done', $t->updated_at, $t->assignee, 'Completed', $t->title, $t->project, static::projectHref($t->project_id, 'Tasks')));

        return $added->merge($done);
    }

    private static function messages($scope, int $limit): Collection
    {
        return $scope(Message::with(['project:id,name', 'senderUser:id,name', 'senderContact:id,name', 'parent:id,subject']))
            ->whereNotNull('sent_at')
            ->latest('sent_at')->limit($limit)->get()->toBase()
            ->map(fn (Message $m) => static::event(
                $m->parent_id ? 'reply' : 'message',
                $m->sent_at,
                $m->senderUser?->name ?? $m->senderContact?->name,
                $m->parent_id ? 'replied to' : 'started',
                $m->parent_id ? ($m->parent?->subject ?: 'a message') : ($m->subject ?: 'a message'),
                $m->project,
                static::projectHref($m->project_id, 'Messages'),
            ));
    }

    private static function notes($scope, int $limit): Collection
    {
        return $scope(Note::with(['project:id,name', 'user:id,name']))->latest()->limit($limit)->get()->toBase()
            ->map(fn (Note $n) => static::event('note', $n->created_at, $n->user?->name, 'added a note', $n->title ?: 'Untitled', $n->project, static::projectHref($n->project_id, 'Notes')));
    }

    private static function files(?array $projectIds, int $limit): Collection
    {
        $query = TaskFile::with('task:id,title,project_id', 'task.project:id,name');
        if ($projectIds !== null) {
            $query->whereHas('task', fn ($q) => $q->whereIn('project_id', $projectIds));
        }

        return $query->latest()->limit($limit)->get()->toBase()
            ->filter(fn (TaskFile $f) => $f->task)
            ->map(fn (TaskFile $f) => static::event('file', $f->created_at, null, 'File added to '.$f->task->title, $f->filename, $f->task->project, static::projectHref($f->task->project_id, 'Tasks')));
    }

    // One line per person, project and day ("logged 6.5h"), so a day of
    // entries doesn't crowd out everything else.
    private static function time($scope, int $limit): Collection
    {
        return $scope(TimeEntry::with(['project:id,name', 'user:id,name', 'service:id,name']))->whereNotNull('project_id')
            ->latest('date')->latest()->limit($limit * 6)->get()->toBase()
            ->groupBy(fn (TimeEntry $t) => "{$t->user_id}-{$t->project_id}-{$t->date->toDateString()}")
            ->map(function (Collection $day) {
                $first = $day->first();
                $hours = rtrim(rtrim(number_format((float) $day->sum('hours'), 2), '0'), '.');

                // Placed by the day worked: time logged later for an earlier
                // day sits back on that day, not at the top.
                $at = $day->max('created_at')->min($first->date->copy()->endOfDay());

                return static::event(
                    'time',
                    $at,
                    $first->user?->name,
                    "logged {$hours}h",
                    $day->map(fn (TimeEntry $t) => $t->service?->name)->filter()->unique()->implode(', ') ?: null,
                    $first->project,
                    static::projectHref($first->project_id, 'Time'),
                );
            })
            ->values();
    }

    private static function proposalsSent($scope, int $limit): Collection
    {
        return $scope(Proposal::with(['project:id,name', 'company:id,name']))->whereNotNull('sent_at')->latest('sent_at')->limit($limit)->get()->toBase()
            ->map(fn (Proposal $p) => static::event('proposal_sent', $p->sent_at, null, 'Proposal sent to '.$p->company?->name, $p->title, $p->project, "/proposals/{$p->id}/edit", (float) $p->estimate_amount));
    }

    // Opens on the project's Proposals tab, which everyone on it can see.
    private static function proposalsAccepted($scope, int $limit): Collection
    {
        return $scope(Proposal::with(['project:id,name', 'company:id,name']))->whereNotNull('accepted_at')->latest('accepted_at')->limit($limit)->get()->toBase()
            ->map(fn (Proposal $p) => static::event('proposal_accepted', $p->accepted_at, $p->company?->name, 'accepted', $p->title, $p->project, $p->project_id ? static::projectHref($p->project_id, 'Proposals') : "/proposals/{$p->id}/edit", (float) $p->estimate_amount));
    }

    private static function invoices($scope, int $limit): Collection
    {
        return $scope(Invoice::with(['project:id,name', 'company:id,name', 'items']))->whereNotNull('sent_at')
            ->latest('sent_at')->limit($limit)->get()->toBase()
            ->map(fn (Invoice $i) => static::event('invoice_sent', $i->sent_at, null, 'Invoice sent to '.$i->company?->name, "#{$i->invoice_number}", $i->project, "/invoices/{$i->id}", $i->total()));
    }

    private static function payments(?array $projectIds, int $limit): Collection
    {
        return Payment::with(['invoice:id,invoice_number,company_id,project_id', 'invoice.company:id,name', 'invoice.project:id,name'])
            ->when($projectIds !== null, fn ($q) => $q->whereHas('invoice', fn ($i) => $i->whereIn('project_id', $projectIds)))
            ->whereNotNull('paid_at')->latest('paid_at')->limit($limit)->get()->toBase()
            ->filter(fn (Payment $p) => $p->invoice)
            ->map(fn (Payment $p) => static::event('payment', $p->paid_at, $p->invoice->company?->name, 'paid', "#{$p->invoice->invoice_number}", $p->invoice->project, "/invoices/{$p->invoice->id}", (float) $p->amount));
    }
}

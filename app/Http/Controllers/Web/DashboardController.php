<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\UnreadMessages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// The Overview: the latest activity across projects, with lists of recent
// projects, messages and tasks due, and the year's hours. Managers see the
// whole studio plus its money (invoice figures, the income chart, invoice
// and payment activity); a team member sees only their assigned projects,
// their own hours, and nothing financial -- those props aren't sent.
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $manager = ! $user->isTeamMember();
        $projectIds = $manager ? null : $user->activeProjects()->pluck('projects.id')->all();
        $scoped = fn ($query, string $column = 'project_id') => $projectIds === null ? $query : $query->whereIn($column, $projectIds);
        $year = (int) now()->year;
        $unread = UnreadMessages::countsByProject($user);

        return Inertia::render('Dashboard/Index', [
            'isManager' => $manager,
            'metrics' => $manager ? $this->managerMetrics() : $this->teamMetrics($user, $projectIds, $unread),
            'activity' => ActivityFeed::for($user),
            'projects' => $this->recentProjects($scoped, $unread),
            'threads' => $this->recentThreads($scoped, $user),
            'tasks' => $this->tasksDue($scoped, $manager ? null : $user),
            'hours' => ['year' => $year, 'months' => $this->hoursByMonth($year, $manager ? null : $user)],
            'income' => $manager ? ['year' => $year, 'months' => Transaction::yearSeries($year)] : null,
        ]);
    }

    private function managerMetrics(): array
    {
        $open = Invoice::with(['items', 'payments'])->where('status', 'sent')->get();
        $overdue = $open->filter(fn (Invoice $i) => $i->due_on && $i->due_on->lt(today()));

        return [
            'outstanding' => ['count' => $open->count(), 'amount' => round($open->sum(fn (Invoice $i) => $i->remainingBalance()), 2)],
            'overdue' => ['count' => $overdue->count(), 'amount' => round($overdue->sum(fn (Invoice $i) => $i->remainingBalance()), 2)],
            'paid_this_month' => round((float) Payment::whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'), 2),
            'unbilled_hours' => round((float) TimeEntry::where('billable', true)->where('billed', false)->sum('hours'), 2),
        ];
    }

    private function teamMetrics(User $user, array $projectIds, array $unread): array
    {
        return [
            'week_hours' => round((float) TimeEntry::where('user_id', $user->id)->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])->sum('hours'), 2),
            'projects' => count($projectIds),
            'open_tasks' => Task::whereIn('project_id', $projectIds)->where('assignee', $user->name)->where('status', '!=', 'done')->count(),
            'unread_messages' => array_sum($unread),
        ];
    }

    // The newest projects still in play.
    private function recentProjects($scoped, array $unread): array
    {
        return $scoped(Project::with('company:id,name'), 'id')
            ->whereNotIn('status', ['completed', 'archived'])
            ->latest()->limit(5)->get(['id', 'name', 'status', 'company_id', 'created_at'])
            ->map(fn (Project $p) => [...$p->toArray(), 'unread_messages' => $unread[$p->id] ?? 0])
            ->all();
    }

    // The threads with the latest messages (a reply counts as activity on
    // its thread), with who wrote last and whether it's unread here.
    private function recentThreads($scoped, User $user): array
    {
        $threadIds = $scoped(Message::query())->whereNotNull('sent_at')
            ->selectRaw('coalesce(parent_id, id) as thread_id, max(sent_at) as last_at')
            ->groupByRaw('coalesce(parent_id, id)')
            ->orderByDesc('last_at')->limit(5)->pluck('thread_id');

        return Message::whereIn('id', $threadIds)->with([...Message::threadRelations(), 'project:id,name'])->get()
            ->map(function (Message $thread) use ($user) {
                $last = collect([$thread])->merge($thread->replies)->reject->trashed()->sortByDesc('sent_at')->first() ?? $thread;

                return [
                    'id' => $thread->id,
                    'subject' => $thread->subject,
                    'project' => $thread->project?->only('id', 'name'),
                    'last_at' => $last->sent_at?->toIso8601String(),
                    'last_sender' => $last->senderUser?->name ?? $last->senderContact?->name,
                    'replies' => $thread->replies->reject->trashed()->count(),
                    'unread' => UnreadMessages::isUnread($thread, $user),
                ];
            })
            ->sortByDesc('last_at')->values()->all();
    }

    // Open tasks with a due date, soonest (overdue) first; a team member's
    // own only.
    private function tasksDue($scoped, ?User $user): array
    {
        $query = $scoped(Task::with('project:id,name'))->where('status', '!=', 'done')->whereNotNull('due_date');
        if ($user) {
            $query->where('assignee', $user->name);
        }

        return $query->orderBy('due_date')->limit(6)->get(['id', 'title', 'due_date', 'assignee', 'status', 'project_id'])->toArray();
    }

    // Hours logged each month this year, billable and not; months still to
    // come null. Everyone's for a manager, a team member's own.
    private function hoursByMonth(int $year, ?User $user): array
    {
        $entries = TimeEntry::whereYear('date', $year)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->get(['date', 'hours', 'billable'])
            ->groupBy(fn (TimeEntry $t) => $t->date->month);

        return collect(range(1, 12))->map(function (int $m) use ($entries) {
            if ($m > now()->month) {
                return ['month' => $m, 'billable' => null, 'other' => null, 'total' => null];
            }
            $month = $entries->get($m, collect());
            $billable = round((float) $month->where('billable', true)->sum('hours'), 2);
            $total = round((float) $month->sum('hours'), 2);

            return ['month' => $m, 'billable' => $billable, 'other' => round($total - $billable, 2), 'total' => $total];
        })->all();
    }
}

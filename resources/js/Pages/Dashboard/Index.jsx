import { Head, Link } from '@inertiajs/react';
import {
    ArrowBendUpLeft, ChatCircle, CheckCircle, Clock, CurrencyDollar, Handshake, Note, Paperclip, PaperPlaneTilt, Plus, Receipt,
} from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import EmptyState from '../../Components/EmptyState';
import YearChart from '../../Components/YearChart';
import { ProjectStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, formatDaySeparator, formatRelativeTime, todayInAppTimezone } from '../../lib/format';
import PageHeader from '../../Components/PageHeader';

const ICONS = {
    task_added: Plus,
    task_done: CheckCircle,
    message: ChatCircle,
    reply: ArrowBendUpLeft,
    note: Note,
    file: Paperclip,
    time: Clock,
    proposal_sent: PaperPlaneTilt,
    proposal_accepted: Handshake,
    invoice_sent: Receipt,
    payment: CurrencyDollar,
};

// Money in (accepted, paid) gets the red; everything else stays quiet.
const HIGHLIGHT = ['proposal_accepted', 'payment'];

// The hours chart: billable and other hours as the bars, the month's total
// as the line.
const HOURS_SERIES = {
    bars: [
        { key: 'billable', label: 'Billable', tone: 'primary' },
        { key: 'other', label: 'Non-billable', tone: 'muted' },
    ],
    line: { key: 'total', label: 'Total' },
    format: 'hours',
    floor: 40,
    ariaLabel: 'Hours logged by month',
};

function hoursLabel(n) {
    return `${Math.round(n * 100) / 100}h`;
}

function ActivityItem({ event }) {
    const Icon = ICONS[event.type] ?? Plus;

    return (
        <Link href={event.href} className="overview-activity__item">
            <span className={`overview-activity__icon${HIGHLIGHT.includes(event.type) ? ' overview-activity__icon--primary' : ''}`}>
                <Icon />
            </span>
            <div className="overview-activity__body">
                <div className="overview-activity__text">
                    {event.actor && <strong>{event.actor} </strong>}
                    {event.text}
                    {event.subject && <> <span className="overview-activity__subject">&ldquo;{event.subject}&rdquo;</span></>}
                    {event.amount > 0 && <span className="overview-activity__amount"> &middot; {formatCurrency(event.amount)}</span>}
                </div>
                <div className="overview-activity__meta">
                    {event.project && <>{event.project.name} &middot; </>}
                    {formatRelativeTime(event.at)}
                </div>
            </div>
        </Link>
    );
}

// The feed, under a heading for each day.
function Activity({ events }) {
    const days = [];
    events.forEach((event) => {
        const label = formatDaySeparator(event.at);
        if (days.at(-1)?.label !== label) days.push({ label, events: [] });
        days.at(-1).events.push(event);
    });

    return (
        <section>
            <h2 className="section-heading">Latest activity</h2>
            <div className="card card--padded overview-activity">
                {days.length === 0 ? (
                    <EmptyState text="Nothing's happened on your projects yet." />
                ) : days.map((day) => (
                    <div key={day.label} className="overview-activity__day">
                        <div className="overview-activity__day-label">{day.label}</div>
                        {day.events.map((event, i) => <ActivityItem key={`${event.type}-${event.at}-${i}`} event={event} />)}
                    </div>
                ))}
            </div>
        </section>
    );
}

function RecentProjects({ projects }) {
    return (
        <section>
            <h2 className="section-heading">Latest projects</h2>
            <div className="card">
                {projects.length === 0 ? (
                    <EmptyState text="No active projects." />
                ) : projects.map((project) => (
                    <Link key={project.id} href={`/projects/${project.id}`} className="list-row">
                        <div>
                            <div className="list-row__title">{project.name}</div>
                            <div className="list-row__meta">{project.company?.name}</div>
                        </div>
                        <div className="list-row__aside">
                            {project.unread_messages > 0 && (
                                <span className="overview__unread" title={`${project.unread_messages} unread`}>{project.unread_messages}</span>
                            )}
                            <ProjectStatusBadge project={project} />
                        </div>
                    </Link>
                ))}
            </div>
        </section>
    );
}

function RecentMessages({ threads }) {
    return (
        <section>
            <h2 className="section-heading">Recent messages</h2>
            <div className="card">
                {threads.length === 0 ? (
                    <EmptyState text="No messages yet." />
                ) : threads.map((thread) => (
                    <Link key={thread.id} href={`/projects/${thread.project?.id}?tab=Messages`} className="list-row">
                        <div className="overview__clip">
                            <div className="list-row__title">
                                {thread.unread && <span className="overview__dot" aria-label="Unread" />}
                                {thread.subject || 'Message'}
                            </div>
                            <div className="list-row__meta">
                                {thread.project?.name}
                                {thread.last_sender && <> &middot; {thread.last_sender}</>}
                                {thread.replies > 0 && <> &middot; {thread.replies} {thread.replies === 1 ? 'reply' : 'replies'}</>}
                            </div>
                        </div>
                        <span className="list-row__meta">{formatRelativeTime(thread.last_at)}</span>
                    </Link>
                ))}
            </div>
        </section>
    );
}

function TasksDue({ tasks, mine }) {
    const today = todayInAppTimezone();

    return (
        <section>
            <h2 className="section-heading">{mine ? 'Your tasks due' : 'Tasks due'}</h2>
            <div className="card">
                {tasks.length === 0 ? (
                    <EmptyState text="Nothing due." />
                ) : tasks.map((task) => {
                    const overdue = task.due_date.slice(0, 10) < today;
                    return (
                        <Link key={task.id} href={`/projects/${task.project_id}?tab=Tasks`} className="list-row">
                            <div className="overview__clip">
                                <div className="list-row__title">{task.title}</div>
                                <div className="list-row__meta">
                                    {task.project?.name}
                                    {!mine && task.assignee && <> &middot; {task.assignee}</>}
                                </div>
                            </div>
                            <span className={`list-row__meta${overdue ? ' overview__overdue' : ''}`}>
                                {overdue ? 'Overdue · ' : ''}{formatDate(task.due_date)}
                            </span>
                        </Link>
                    );
                })}
            </div>
        </section>
    );
}

// The Overview, by permission (`can`, from the server, which leaves out
// what isn't allowed): invoice figures up top with Invoices, the income
// chart with Bookkeeping, everyone's hours and projects with All projects;
// otherwise their own hours, tasks and assigned projects.
export default function DashboardIndex({ can = {}, metrics, activity, projects, threads, tasks, hours, income }) {
    return (
        <AppLayout>
            <Head title="Overview" />
            <PageHeader title="Overview" />

            {can.invoices ? (
                <div className="metric-grid">
                    <MetricCard label={`Outstanding (${metrics.outstanding.count})`} value={formatCurrency(metrics.outstanding.amount)} />
                    <MetricCard label="Paid this month" value={formatCurrency(metrics.paid_this_month)} />
                    <MetricCard label="Unbilled hours" value={hoursLabel(metrics.unbilled_hours)} />
                    <MetricCard tone="primary" label={`Overdue (${metrics.overdue.count})`} value={formatCurrency(metrics.overdue.amount)} />
                </div>
            ) : (
                <div className="metric-grid">
                    <MetricCard tone="primary" label="Your hours this week" value={hoursLabel(metrics.week_hours)} />
                    <MetricCard label="Your projects" value={metrics.projects} />
                    <MetricCard label="Your open tasks" value={metrics.open_tasks} />
                    <MetricCard label="Unread messages" value={metrics.unread_messages} />
                </div>
            )}

            <div className={`overview__charts${income ? ' overview__charts--pair' : ''}`}>
                {income && <YearChart title={`Income, ${income.year} so far`} months={income.months} year={income.year} />}
                <YearChart
                    title={`${can.all_projects ? 'Hours' : 'Your hours'}, ${hours.year} so far`}
                    months={hours.months}
                    year={hours.year}
                    series={HOURS_SERIES}
                />
            </div>

            <div className="overview__grid">
                <Activity events={activity} />
                <div className="overview__side">
                    <RecentProjects projects={projects} />
                    <RecentMessages threads={threads} />
                    <TasksDue tasks={tasks} mine={!can.all_projects} />
                </div>
            </div>
        </AppLayout>
    );
}

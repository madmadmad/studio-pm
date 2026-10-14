import { Head, Link, router, usePage } from '@inertiajs/react';
import { UserCircle } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import PageHeader from '../../Components/PageHeader';
import TabBar from '../../Components/TabBar';
import Timesheet from '../../Components/Timesheet';
import ProjectBoard from '../../Components/ProjectBoard';
import EmptyState from '../../Components/EmptyState';
import StarButton, { useFavorites } from '../../Components/StarButton';
import RowActions from '../../Components/RowActions';
import { ProjectStatusBadge, TaskStatusBadge } from '../../Components/StatusBadges';
import { api } from '../../lib/api';
import { formatDate, todayInAppTimezone } from '../../lib/format';
import { useRememberedTab } from '../../lib/useRememberedTab';

const TABS = ['Tasks', 'Projects', 'Timesheet'];

// Their starred projects as a board, as on the Projects page: drag a card
// to change its status, or unstar it to take it off here.
function StarredProjects({ projects }) {
    const favorites = useFavorites(projects);
    const starred = projects.filter((p) => favorites.isStarred(p));

    if (starred.length === 0) {
        return (
            <div className="card card--flush">
                <EmptyState text="No starred projects yet." />
                <p className="form-hint my-work__hint">
                    Star the ones you&rsquo;re working on from the <Link href="/projects" className="link link--inline">Projects</Link> page.
                </p>
            </div>
        );
    }

    return <ProjectBoard projects={starred} favorites={favorites} onChange={() => router.reload({ only: ['starredProjects'] })} />;
}

const STATUS_ORDER = ['todo', 'in_progress', 'done'];

// Their tasks across every project -- assigned to them or starred -- open
// ones first by due date. A row opens the task on its project; the status
// badge cycles it (when they can change it). Unstarring takes a task off
// here unless it's assigned to them.
function MyTasks({ tasks }) {
    const favorites = useFavorites(tasks, 'tasks');
    const shown = tasks.filter((t) => t.is_assigned || favorites.isStarred(t));
    const today = todayInAppTimezone();

    async function cycleStatus(e, task) {
        e.stopPropagation();
        const next = STATUS_ORDER[(STATUS_ORDER.indexOf(task.status) + 1) % STATUS_ORDER.length];
        await api.patch(`/api/tasks/${task.id}`, { status: next });
        router.reload({ only: ['myTasks'] });
    }

    if (shown.length === 0) {
        return (
            <div className="card card--flush">
                <EmptyState text="No tasks yet." />
                <p className="form-hint my-work__hint">
                    Tasks assigned to you show up here. Star others you&rsquo;re working on from a project&rsquo;s Tasks tab.
                </p>
            </div>
        );
    }

    return (
        <div className="card card--flush">
            <div className="grid-row grid-row--action grid-row--head">
                <div className="my-work-tasks__title">Task</div>
                <div className="my-work-tasks__project">Project</div>
                <div className="my-work-tasks__due">Due date</div>
                <div className="my-work-tasks__status">Status</div>
                <div />
            </div>
            {shown.map((task) => {
                const overdue = task.due_date && task.status !== 'done' && task.due_date.slice(0, 10) < today;
                return (
                    <div
                        key={task.id}
                        onClick={() => router.visit(`/projects/${task.project_id}?tab=Tasks&task=${task.id}`)}
                        className="grid-row grid-row--action grid-row--link"
                    >
                        <div className="my-work-tasks__title">
                            <StarButton starred={favorites.isStarred(task)} onToggle={() => favorites.toggle(task)} size={14} noun="task" />
                            <span className="u-truncate">{task.title}</span>
                            {task.is_assigned && (
                                <UserCircle className="my-work-tasks__assigned" aria-label="Assigned to you" title="Assigned to you" />
                            )}
                        </div>
                        <div className="my-work-tasks__project">
                            <span className="u-truncate">{task.project?.name}</span>
                            {task.project?.company && <span className="my-work-tasks__client u-truncate">{task.project.company.name}</span>}
                        </div>
                        <div className={`my-work-tasks__due${task.due_date ? '' : ' my-work-tasks__empty'}${overdue ? ' my-work-tasks__due--overdue' : ''}`}>
                            {task.due_date ? formatDate(task.due_date) : '—'}
                        </div>
                        <div className="my-work-tasks__status">
                            {task.can_edit ? (
                                <button onClick={(e) => cycleStatus(e, task)}>
                                    <TaskStatusBadge task={task} />
                                </button>
                            ) : (
                                <TaskStatusBadge task={task} />
                            )}
                        </div>
                        <RowActions openLabel="Open task" />
                    </div>
                );
            })}
        </div>
    );
}

// The projects they've logged time on in the last 30 days, most recent
// first, with their hours on each over that time. A row opens the project.
function RecentProjects({ projects }) {
    if (projects.length === 0) {
        return (
            <div className="card card--flush page-section">
                <EmptyState text="No time logged in the last 30 days." />
            </div>
        );
    }

    return (
        <div className="card card--flush page-section">
            <div className="grid-row grid-row--action grid-row--head">
                <div className="my-work-projects__name">Project</div>
                <div className="my-work-projects__last">Last worked on</div>
                <div className="my-work-projects__hours">Your hours</div>
                <div className="my-work-projects__status">Status</div>
                <div />
            </div>
            {projects.map((project) => (
                <div key={project.id} onClick={() => router.visit(`/projects/${project.id}`)} className="grid-row grid-row--action grid-row--link">
                    <div className="my-work-projects__name">
                        <span className="u-truncate">{project.name}</span>
                        {project.company && <span className="my-work-projects__client u-truncate">{project.company.name}</span>}
                    </div>
                    <div className="my-work-projects__last">{formatDate(project.last_worked_on)}</div>
                    <div className="my-work-projects__hours">{parseFloat(project.hours)}h</div>
                    <div className="my-work-projects__status"><ProjectStatusBadge project={project} /></div>
                    <RowActions openLabel="Open project" />
                </div>
            ))}
        </div>
    );
}

// My Work: the signed-in person's own work, and where they land -- their
// tasks (assigned or starred), their projects (recently worked on, then
// starred), and their week's time. Their account and security are Profile.
export default function MyWorkIndex({ myTasks, recentProjects, starredProjects, timesheet, timeProjects, timeCompanies, timeServices }) {
    const [tab, setTab] = useRememberedTab('my-work-tab', TABS, { param: 'tab' });
    const { props } = usePage();

    return (
        <AppLayout>
            <Head title="My Work" />
            <PageHeader title="My Work" />
            <TabBar tabs={TABS} tab={tab} setTab={setTab} size="lg" />

            {tab === 'Tasks' && <MyTasks tasks={myTasks} />}

            {tab === 'Projects' && (
                <>
                    <div className="section-label">Recently worked on</div>
                    <RecentProjects projects={recentProjects} />
                    <div className="section-label">Starred</div>
                    <StarredProjects projects={starredProjects} />
                </>
            )}

            {tab === 'Timesheet' && (
                <Timesheet
                    timesheet={timesheet}
                    projects={timeProjects}
                    companies={timeCompanies}
                    services={timeServices}
                    currentUser={props.auth.user}
                />
            )}
        </AppLayout>
    );
}

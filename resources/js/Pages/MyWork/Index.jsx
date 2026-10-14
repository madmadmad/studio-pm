import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Check, DotsSixVertical, UserCircle, X } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import PageHeader from '../../Components/PageHeader';
import TabBar from '../../Components/TabBar';
import Timesheet from '../../Components/Timesheet';
import ProjectBoard from '../../Components/ProjectBoard';
import EmptyState from '../../Components/EmptyState';
import AutoResizeTextarea from '../../Components/AutoResizeTextarea';
import StarButton, { useFavorites } from '../../Components/StarButton';
import RowActions from '../../Components/RowActions';
import { ProjectStatusBadge, TaskStatusBadge } from '../../Components/StatusBadges';
import { api } from '../../lib/api';
import { formatDate, formatDateTime, todayInAppTimezone } from '../../lib/format';
import { useRememberedTab } from '../../lib/useRememberedTab';

const TABS = ['Projects', 'Tasks', 'Timesheet', 'To-do'];

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

function reloadTodos() {
    router.reload({ only: ['todos'] });
}

// One line of the to-do list: tick it, edit it in place (Enter or leaving
// the field saves), drag it (open ones only), or remove it. When it was
// written down sits on the right (the full date and time on hover).
function TodoRow({ todo, drag }) {
    const [title, setTitle] = useState(todo.title);
    useEffect(() => setTitle(todo.title), [todo.id, todo.title]);
    const done = Boolean(todo.done_at);

    async function save() {
        const value = title.trim();
        if (!value) {
            setTitle(todo.title);
            return;
        }
        if (value !== todo.title) {
            await api.patch(`/api/todos/${todo.id}`, { title: value });
            reloadTodos();
        }
    }

    async function toggle() {
        await api.patch(`/api/todos/${todo.id}`, { done: !done });
        reloadTodos();
    }

    async function remove() {
        await api.delete(`/api/todos/${todo.id}`);
        reloadTodos();
    }

    return (
        <div
            draggable={Boolean(drag)}
            onDragStart={drag?.onDragStart}
            onDragOver={drag?.onDragOver}
            onDrop={drag?.onDrop}
            onDragEnd={drag?.onDragEnd}
            className={`subtask-list__item${drag?.dragging ? ' subtask-list__item--dragging' : ''}${drag ? '' : ' subtask-list__item--draft'}`}
        >
            <span className="subtask-list__handle" title="Drag to reorder">
                <DotsSixVertical size={14} weight="bold" />
            </span>
            <button
                type="button"
                onClick={toggle}
                aria-label={done ? `Mark "${todo.title}" not done` : `Mark "${todo.title}" done`}
                className={`subtask-list__check${done ? ' subtask-list__check--done' : ''}`}
            >
                {done && <Check size={12} weight="bold" />}
            </button>
            <AutoResizeTextarea
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onBlur={save}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        e.target.blur();
                    }
                }}
                aria-label="To-do"
                className={`subtask-list__title${done ? ' subtask-list__title--done' : ''}`}
            />
            <time dateTime={todo.created_at} title={`Added ${formatDateTime(todo.created_at)}`} className="my-work-todo__date">
                {formatDate(todo.created_at)}
            </time>
            <button type="button" onClick={remove} title="Remove" aria-label={`Remove "${todo.title}"`} className="icon-btn icon-btn--danger subtask-list__remove">
                <X />
            </button>
        </div>
    );
}

// Their own running to-do list: private, not tied to a project. The open
// ones in the order they're dragged into, with a field at the end to add
// the next (Enter adds it and stays there for another); ticked ones move
// to Done, newest first, until cleared.
function TodoList({ todos }) {
    const [open, setOpen] = useState(todos.open);
    useEffect(() => setOpen(todos.open), [todos.open]);
    const [draft, setDraft] = useState('');
    const [dragId, setDragId] = useState(null);

    async function add(e) {
        e.preventDefault();
        const value = draft.trim();
        if (!value) return;
        setDraft('');
        await api.post('/api/todos', { title: value });
        reloadTodos();
    }

    async function move(fromId, toId) {
        if (fromId === null || fromId === toId) return;
        const next = [...open];
        const [moved] = next.splice(next.findIndex((t) => t.id === fromId), 1);
        next.splice(next.findIndex((t) => t.id === toId), 0, moved);
        setOpen(next);
        await api.put('/api/todos/order', { ids: next.map((t) => t.id) });
        reloadTodos();
    }

    async function clearDone() {
        if (!confirm(`Clear ${todos.done.length} done to-do${todos.done.length === 1 ? '' : 's'}? This can't be undone.`)) return;
        await api.delete('/api/todos/done');
        reloadTodos();
    }

    return (
        <>
            <div className="card card--padded page-section">
                <div className="subtask-list">
                    {open.map((todo) => (
                        <TodoRow
                            key={todo.id}
                            todo={todo}
                            drag={{
                                dragging: dragId === todo.id,
                                onDragStart: () => setDragId(todo.id),
                                onDragOver: (e) => e.preventDefault(),
                                onDrop: () => move(dragId, todo.id),
                                onDragEnd: () => setDragId(null),
                            }}
                        />
                    ))}
                    <form onSubmit={add} className="subtask-list__item subtask-list__item--draft">
                        <span className="subtask-list__handle" aria-hidden="true">
                            <DotsSixVertical size={14} weight="bold" />
                        </span>
                        <span className="subtask-list__check" aria-hidden="true" />
                        <input
                            autoFocus
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            placeholder={open.length ? 'Add another…' : 'Add a to-do…'}
                            aria-label="New to-do"
                            maxLength={500}
                            className="subtask-list__title"
                        />
                    </form>
                </div>
                <p className="form-hint">Only you can see this list.</p>
            </div>

            {todos.done.length > 0 && (
                <div className="card card--padded">
                    <div className="form-panel__header">
                        <div className="section-label section-label--flush">Done</div>
                        <button type="button" onClick={clearDone} className="text-action text-action--sm">Clear done</button>
                    </div>
                    <div className="subtask-list">
                        {todos.done.map((todo) => <TodoRow key={todo.id} todo={todo} />)}
                    </div>
                </div>
            )}
        </>
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
// projects (recently worked on, then starred), their tasks (assigned or
// starred), their week's time, and their private to-do list. Their
// account and security are Profile.
export default function MyWorkIndex({ myTasks, todos, recentProjects, starredProjects, timesheet, timeProjects, timeCompanies, timeServices }) {
    const [tab, setTab] = useRememberedTab('my-work-tab', TABS, { param: 'tab' });
    const { props } = usePage();

    return (
        <AppLayout>
            <Head title="My Work" />
            <PageHeader title="My Work" />
            <TabBar tabs={TABS} tab={tab} setTab={setTab} size="lg" />

            {tab === 'Projects' && (
                <>
                    <div className="section-label">Recently worked on</div>
                    <RecentProjects projects={recentProjects} />
                    <div className="section-label">Starred</div>
                    <StarredProjects projects={starredProjects} />
                </>
            )}

            {tab === 'Tasks' && <MyTasks tasks={myTasks} />}

            {tab === 'Timesheet' && (
                <Timesheet
                    timesheet={timesheet}
                    projects={timeProjects}
                    companies={timeCompanies}
                    services={timeServices}
                    currentUser={props.auth.user}
                />
            )}

            {tab === 'To-do' && <TodoList todos={todos} />}
        </AppLayout>
    );
}

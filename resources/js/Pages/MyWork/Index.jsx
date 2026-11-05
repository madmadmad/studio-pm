import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CaretDown, CaretRight, Check, DotsSixVertical, DotsThree, FolderSimple, Plus, UserCircle, X } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import PageHeader from '../../Components/PageHeader';
import TabBar from '../../Components/TabBar';
import Timesheet from '../../Components/Timesheet';
import ProjectBoard from '../../Components/ProjectBoard';
import EmptyState from '../../Components/EmptyState';
import AutoResizeTextarea from '../../Components/AutoResizeTextarea';
import StarButton, { useFavorites } from '../../Components/StarButton';
import RowActions from '../../Components/RowActions';
import ActionMenu from '../../Components/ActionMenu';
import { ProjectStatusBadge, TaskStatusBadge } from '../../Components/StatusBadges';
import { api } from '../../lib/api';
import { formatDateTime, todayInAppTimezone, formatShortDate } from '../../lib/format';
import { useRememberedTab } from '../../lib/useRememberedTab';
import { clearDraft, readDraft, writeDraft } from '../../lib/drafts';

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
                            {task.due_date ? formatShortDate(task.due_date) : '—'}
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

// Starred ones above the rest, each keeping its order -- as the server
// lists them (TodoController::listFor).
function starredFirst(todos) {
    return [...todos.filter((t) => t.starred), ...todos.filter((t) => !t.starred)];
}

// A category's color, for its dot, rule and label (--todo-color).
const GRAY = '#8A9097';
const colorStyle = (category) => ({ '--todo-color': category?.color || GRAY });

// The open ones sorted into their sections: each category in its order,
// then those in no category -- starred first in each, otherwise as
// they're ordered.
function sectionsOf(open, categories) {
    const known = new Set(categories.map((c) => c.id));
    const loose = open.filter((t) => !t.todo_category_id || !known.has(t.todo_category_id));
    return [
        ...categories.map((category) => ({ category, todos: starredFirst(open.filter((t) => t.todo_category_id === category.id)) })),
        { category: null, todos: starredFirst(loose) },
    ];
}

// Dragging on the to-do list, by the pointer rather than the browser's
// own drag-and-drop -- which Safari and Firefox won't start on a row of
// editable text -- so it works the same everywhere, touch included.
// Pressing a handle (`start`) picks up a to-do or a category; past a few
// pixels it's a drag, and whatever is under the pointer with a
// `data-drop` key ("todo:<id>", "category:<id>", "end:<category id or
// none>") is where it would land. Letting go there calls `onDrop(item,
// key)`; Escape, or letting go anywhere else, puts it back.
function usePointerDrag(onDrop) {
    const [drag, setDrag] = useState(null);
    const current = useRef(null);
    const dropRef = useRef(onDrop);
    dropRef.current = onDrop;

    function start(e, kind, id) {
        if (e.button !== 0) return;
        e.preventDefault();
        current.current = { kind, id, x: e.clientX, y: e.clientY, active: false, over: null };

        function move(ev) {
            const now = current.current;
            if (!now.active && Math.hypot(ev.clientX - now.x, ev.clientY - now.y) < 4) return;
            const over = document.elementFromPoint(ev.clientX, ev.clientY)?.closest('[data-drop]')?.dataset.drop ?? null;
            if (now.active && over === now.over) return;
            current.current = { ...now, active: true, over };
            setDrag(current.current);
            document.body.classList.add('is-sorting');
        }

        function finish(ev) {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', finish);
            window.removeEventListener('pointercancel', finish);
            window.removeEventListener('keydown', escape);
            document.body.classList.remove('is-sorting');
            const done = current.current;
            current.current = null;
            setDrag(null);
            if (ev?.type === 'pointerup' && done?.active && done.over) dropRef.current(done, done.over);
        }

        function escape(ev) {
            if (ev.key === 'Escape') finish();
        }

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', finish);
        window.addEventListener('pointercancel', finish);
        window.addEventListener('keydown', escape);
    }

    return { drag, start };
}

// How long after the last keystroke an edit saves.
const SAVE_AFTER_MS = 800;

// One line of the to-do list: tick it, edit it in place, star or drag it
// (open ones only), move it to another category, or remove it. An edit
// saves a moment after typing stops, and on Enter or leaving the line;
// until the server has it, it's kept in the browser (lib/drafts.js), so a
// sign-out or reload never loses it -- one left unsaved is saved when the
// list next loads. When it was written down sits on the right (the full
// date and time on hover), or "Not saved" if the last save failed. A
// ticked one shows the category it was in.
function TodoRow({ todo, drag, onStar, onMove, categories = [], category, userId }) {
    const draftKey = `todo-edit:${userId}:${todo.id}`;
    const [title, setTitle] = useState(() => readDraft(draftKey) ?? todo.title);
    const [unsaved, setUnsaved] = useState(false);
    const saved = useRef(todo.title);
    const timer = useRef(null);
    const done = Boolean(todo.done_at);

    // A newer title from the server -- unless an edit here is still waiting.
    useEffect(() => {
        saved.current = todo.title;
        if (readDraft(draftKey) === null) setTitle(todo.title);
    }, [todo.id, todo.title]);

    // An edit that didn't make it last time: send it now.
    useEffect(() => {
        const pending = readDraft(draftKey);
        if (pending !== null) save(pending);
        return () => clearTimeout(timer.current);
    }, []);

    async function save(value, { leaving = false } = {}) {
        clearTimeout(timer.current);
        const next = value.trim();
        if (!next) {
            // Emptied and left: back to what it was.
            if (leaving) {
                setTitle(saved.current);
                clearDraft(draftKey);
            }
            return;
        }
        if (next === saved.current) {
            if (readDraft(draftKey) === value) clearDraft(draftKey);
            setUnsaved(false);
            return;
        }
        try {
            await api.patch(`/api/todos/${todo.id}`, { title: next });
            saved.current = next;
            // Only if nothing more was typed while it saved.
            if (readDraft(draftKey) === value) clearDraft(draftKey);
            setUnsaved(false);
        } catch {
            setUnsaved(true);
        }
    }

    function change(value) {
        setTitle(value);
        writeDraft(draftKey, value);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => save(value), SAVE_AFTER_MS);
    }

    async function toggle() {
        await api.patch(`/api/todos/${todo.id}`, { done: !done });
        reloadTodos();
    }

    async function remove() {
        await api.delete(`/api/todos/${todo.id}`);
        clearDraft(draftKey);
        reloadTodos();
    }

    const moveTargets = onMove && categories.length > 0
        ? [
            todo.todo_category_id && { label: 'No category', onSelect: () => onMove(null) },
            ...categories.filter((c) => c.id !== todo.todo_category_id).map((c) => ({ label: c.name, onSelect: () => onMove(c.id) })),
        ].filter(Boolean)
        : [];

    return (
        <div
            data-drop={drag ? `todo:${todo.id}` : undefined}
            className={`subtask-list__item${drag?.dragging ? ' subtask-list__item--dragging' : ''}${drag?.drop ? ` todo-drop todo-drop--${drag.drop}` : ''}${drag ? '' : ' subtask-list__item--draft'}`}
        >
            <span className="subtask-list__handle" onPointerDown={drag?.onHandleDown} title="Drag to reorder or move to another category">
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
                onChange={(e) => change(e.target.value)}
                onBlur={() => save(title, { leaving: true })}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        e.target.blur();
                    }
                }}
                aria-label="To-do"
                className={`subtask-list__title${done ? ' subtask-list__title--done' : ''}`}
            />
            {done && category && (
                <span className="my-work-todo__category" style={colorStyle(category)}>
                    <span className="my-work-todo__dot" aria-hidden="true" />
                    <span className="u-truncate">{category.name}</span>
                </span>
            )}
            {moveTargets.length > 0 && (
                <span className="my-work-todo__move">
                    <ActionMenu label="Move to category" icon={<FolderSimple size={14} />} items={moveTargets} />
                </span>
            )}
            {onStar && (
                <StarButton
                    starred={todo.starred}
                    onToggle={onStar}
                    size={14}
                    noun="to-do"
                    className={`my-work-todo__star${todo.starred ? ' my-work-todo__star--on' : ''}`}
                />
            )}
            {unsaved ? (
                <span className="my-work-todo__date my-work-todo__date--unsaved" title="Kept in this browser; it saves when you’re signed in again.">Not saved</span>
            ) : (
                <time dateTime={todo.created_at} title={`Added ${formatDateTime(todo.created_at)}`} className="my-work-todo__date">
                    {formatShortDate(todo.created_at)}
                </time>
            )}
            <button type="button" onClick={remove} title="Remove" aria-label={`Remove "${todo.title}"`} className="icon-btn icon-btn--danger subtask-list__remove">
                <X />
            </button>
        </div>
    );
}

// The line at the end of a section for the next one: Enter adds it (and
// stays there for another), as does leaving the line with something typed.
// What's typed is kept in the browser until the server has it, so it's
// never lost to a sign-out or reload; if adding fails it stays, with the
// reason. Dropping a dragged to-do here puts it at the end of the section.
function AddTodo({ categoryId, userId, placeholder, autoFocus, dropOver }) {
    const draftKey = categoryId ? `todo-new:${userId}:${categoryId}` : `todo-new:${userId}`;
    const [draft, setDraftState] = useState(() => readDraft(draftKey, ''));
    const [error, setError] = useState('');
    const adding = useRef(false);

    function setDraft(value) {
        setDraftState(value);
        writeDraft(draftKey, value);
    }

    async function add(e) {
        e?.preventDefault();
        const value = draft.trim();
        if (!value || adding.current) return;
        adding.current = true;
        setError('');
        try {
            await api.post('/api/todos', { title: value, todo_category_id: categoryId });
            // Cleared once it's in -- unless more was typed meanwhile.
            if (readDraft(draftKey, '').trim() === value) setDraft('');
            reloadTodos();
        } catch (err) {
            setError(err.message || 'Not saved. It’s kept here; press Enter to try again.');
        } finally {
            adding.current = false;
        }
    }

    return (
        <>
            <form
                onSubmit={add}
                data-drop={`end:${categoryId ?? 'none'}`}
                className={`subtask-list__item subtask-list__item--draft${dropOver ? ' todo-drop todo-drop--above' : ''}`}
            >
                <span className="subtask-list__handle" aria-hidden="true">
                    <DotsSixVertical size={14} weight="bold" />
                </span>
                <span className="subtask-list__check" aria-hidden="true" />
                <input
                    autoFocus={autoFocus}
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onBlur={() => add()}
                    placeholder={placeholder}
                    aria-label="New to-do"
                    maxLength={500}
                    className="subtask-list__title"
                />
            </form>
            {error && <div className="form-message form-message--error">{error}</div>}
        </>
    );
}

// How long after the last move in the color picker the color saves.
const COLOR_SAVE_AFTER_MS = 500;

// A category's color dot: opens the system color picker (a native color
// input hidden inside it, as in Components/ColorPicker). The category
// takes each color as it's picked (`onPreview`); it saves (`onChange`) a
// moment after the picking stops, or when the picker closes.
function CategoryColor({ category, onPreview, onChange }) {
    const timer = useRef(null);
    const pending = useRef(null);
    useEffect(() => () => clearTimeout(timer.current), []);

    function save() {
        clearTimeout(timer.current);
        if (pending.current) onChange(pending.current);
        pending.current = null;
    }

    function pick(raw) {
        const color = raw.toUpperCase();
        pending.current = color;
        onPreview(color);
        clearTimeout(timer.current);
        timer.current = setTimeout(save, COLOR_SAVE_AFTER_MS);
    }

    return (
        <label className="todo-category__swatch" title="Change color">
            <input
                type="color"
                value={category.color || GRAY}
                onChange={(e) => pick(e.target.value)}
                onBlur={save}
                aria-label={`Change the color of "${category.name}"`}
            />
        </label>
    );
}

// A category's heading: fold it away, recolor it, rename it in place,
// drag it (or use its menu) to move it, or delete it. A to-do dropped on
// it goes to the end of it.
function CategoryHeader({ category, count, drag, first, last, onChange, onPreview, onShift, onDelete }) {
    const [name, setName] = useState(category.name);
    useEffect(() => setName(category.name), [category.name]);
    const Caret = category.collapsed ? CaretRight : CaretDown;

    function rename() {
        const next = name.trim();
        if (!next) setName(category.name);
        else if (next !== category.name) onChange({ name: next });
    }

    return (
        <div
            data-drop={`category:${category.id}`}
            className={`todo-category${drag.dragging ? ' todo-category--dragging' : ''}${drag.drop ? ` todo-drop todo-drop--${drag.drop}` : ''}`}
        >
            <span className="subtask-list__handle" onPointerDown={drag.onHandleDown} title="Drag to reorder categories">
                <DotsSixVertical size={14} weight="bold" />
            </span>
            <button
                type="button"
                onClick={() => onChange({ collapsed: !category.collapsed })}
                aria-label={category.collapsed ? `Show "${category.name}"` : `Hide "${category.name}"`}
                aria-expanded={!category.collapsed}
                className="icon-btn icon-btn--secondary"
            >
                <Caret size={14} weight="bold" />
            </button>
            <CategoryColor category={category} onPreview={(color) => onPreview({ color })} onChange={(color) => onChange({ color })} />
            {/* As wide as the name: the input sits over a hidden copy of it. */}
            <span className="todo-category__fit" data-value={name || ' '}>
                <input
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    onBlur={rename}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') e.target.blur();
                        if (e.key === 'Escape') {
                            setName(category.name);
                            e.target.blur();
                        }
                    }}
                    aria-label="Category name"
                    maxLength={100}
                    size={1}
                    className="todo-category__name"
                />
            </span>
            <span className="todo-category__count">{count}</span>
            <span className="todo-category__menu">
                <ActionMenu
                    label="Category options"
                    icon={<DotsThree size={16} weight="bold" />}
                    items={[
                        !first && { label: 'Move up', onSelect: () => onShift(-1) },
                        !last && { label: 'Move down', onSelect: () => onShift(1) },
                        { label: 'Delete category', danger: true, onSelect: onDelete },
                    ]}
                />
            </span>
        </div>
    );
}

// A new category's name: Enter makes it, Escape or leaving it empty
// puts the button back.
function AddCategory() {
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState('');
    const [error, setError] = useState('');

    async function add(e) {
        e?.preventDefault();
        const value = name.trim();
        if (!value) {
            setEditing(false);
            return;
        }
        try {
            await api.post('/api/todo-categories', { name: value });
            setName('');
            setEditing(false);
            setError('');
            reloadTodos();
        } catch (err) {
            setError(err.message || 'Not saved.');
        }
    }

    if (!editing) {
        return (
            <button type="button" onClick={() => setEditing(true)} className="text-action text-action--sm todo-category__add">
                <Plus size={12} weight="bold" /> Add category
            </button>
        );
    }

    return (
        <form onSubmit={add} className="todo-category todo-category--new">
            <input
                autoFocus
                value={name}
                onChange={(e) => setName(e.target.value)}
                onBlur={() => add()}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                        setName('');
                        setEditing(false);
                    }
                }}
                placeholder="Name it: Hot, Proposals, Waiting for feedback…"
                aria-label="New category"
                maxLength={100}
                className="todo-category__name todo-category__name--new"
            />
            {error && <div className="form-message form-message--error">{error}</div>}
        </form>
    );
}

// Their own running to-do list: private, not tied to a project. Open ones
// in their categories -- named, colored and ordered by them, each
// foldable -- then those in none (under a plain rule, once there are
// categories), each section with a line at its end for the next. To-dos drag within and between categories (or use "Move to");
// starred ones stay at the top of theirs, in their own dragged order.
// Ticked ones move to Done, newest first, until cleared.
function TodoList({ todos, userId }) {
    const [open, setOpen] = useState(todos.open);
    const [categories, setCategories] = useState(todos.categories);
    useEffect(() => setOpen(todos.open), [todos.open]);
    useEffect(() => setCategories(todos.categories), [todos.categories]);

    const sections = sectionsOf(open, categories);
    const byId = Object.fromEntries(categories.map((c) => [c.id, c]));

    // A to-do dropped on another (targetId) takes its place -- the one
    // there moves down, or up if this came from above it in the same
    // category -- or, dropped at the end of a section (targetId null),
    // goes last in it. Either way into that section's category.
    async function dropTodo(movedId, categoryId, targetId) {
        if (movedId === targetId) return;
        const moved = open.find((t) => t.id === movedId);
        if (!moved) return;
        const changed = (moved.todo_category_id ?? null) !== categoryId;
        const shown = sections.flatMap((section) => section.todos);
        const downward = !changed && targetId !== null && shown.indexOf(moved) < shown.findIndex((t) => t.id === targetId);
        const next = shown.filter((t) => t.id !== moved.id);
        const placed = { ...moved, todo_category_id: categoryId };
        const at = targetId === null ? -1 : next.findIndex((t) => t.id === targetId);
        if (at === -1) next.push(placed);
        else next.splice(downward ? at + 1 : at, 0, placed);

        const ordered = sectionsOf(next, categories).flatMap((s) => s.todos);
        setOpen(ordered);
        await api.put('/api/todos/order', {
            ids: ordered.map((t) => t.id),
            ...(changed && { move: { id: moved.id, todo_category_id: categoryId } }),
        });
        reloadTodos();
    }

    async function moveTodo(todo, categoryId) {
        setOpen(open.map((t) => (t.id === todo.id ? { ...t, todo_category_id: categoryId } : t)));
        await api.patch(`/api/todos/${todo.id}`, { todo_category_id: categoryId });
        reloadTodos();
    }

    async function star(todo) {
        setOpen(open.map((t) => (t.id === todo.id ? { ...t, starred: !t.starred } : t)));
        await api.patch(`/api/todos/${todo.id}`, { starred: !todo.starred });
        reloadTodos();
    }

    async function changeCategory(category, changes) {
        setCategories(categories.map((c) => (c.id === category.id ? { ...c, ...changes } : c)));
        await api.patch(`/api/todo-categories/${category.id}`, changes);
        reloadTodos();
    }

    async function orderCategories(next) {
        setCategories(next);
        await api.put('/api/todo-categories/order', { ids: next.map((c) => c.id) });
        reloadTodos();
    }

    // A category dropped on another takes its place, that one moving
    // down (or up, if this came from above it); dropped on the rule over
    // those in no category (targetId null), it goes last.
    function dropCategory(movedId, targetId) {
        if (movedId === targetId || !byId[movedId] || (targetId !== null && !byId[targetId])) return;
        const next = categories.filter((c) => c.id !== movedId);
        if (targetId === null) {
            if (categories[categories.length - 1].id === movedId) return;
            next.push(byId[movedId]);
        } else {
            const downward = categories.findIndex((c) => c.id === movedId) < categories.findIndex((c) => c.id === targetId);
            const at = next.findIndex((c) => c.id === targetId);
            next.splice(downward ? at + 1 : at, 0, byId[movedId]);
        }
        orderCategories(next);
    }

    // Where a to-do's section is: its category, if it's one of theirs.
    const sectionOf = (todo) => (todo.todo_category_id && byId[todo.todo_category_id] ? todo.todo_category_id : null);

    // Letting go of a dragged to-do or category on a `data-drop` key.
    function drop(item, key) {
        const [type, raw] = key.split(':');
        const id = raw === 'none' ? null : Number(raw);
        if (item.kind === 'category') {
            if (type === 'category') dropCategory(item.id, id);
            return;
        }
        if (type === 'todo') {
            const target = open.find((t) => t.id === id);
            if (target) dropTodo(item.id, sectionOf(target), id);
        } else {
            dropTodo(item.id, id, null);
        }
    }

    const { drag, start } = usePointerDrag(drop);

    // How the thing under a drag shows where it would land: a line above
    // or below it, or (a category heading taking a to-do) lit up.
    function dropMark(key) {
        if (!drag || drag.over !== key) return null;
        const [type, raw] = key.split(':');
        const id = Number(raw);
        if (drag.kind === 'category') {
            if (type !== 'category' || id === drag.id) return null;
            // On the rule over those in no category: last, just above it.
            if (raw === 'none') return categories[categories.length - 1]?.id === drag.id ? null : 'above';
            return categories.findIndex((c) => c.id === drag.id) < categories.findIndex((c) => c.id === id) ? 'below' : 'above';
        }
        if (type === 'category') return 'into';
        if (type === 'end') return 'above';
        if (id === drag.id) return null;
        const shown = sections.flatMap((section) => section.todos);
        const from = shown.findIndex((t) => t.id === drag.id);
        const to = shown.findIndex((t) => t.id === id);
        return from < to && sectionOf(shown[from]) === sectionOf(shown[to]) ? 'below' : 'above';
    }

    function shiftCategory(category, by) {
        const next = [...categories];
        const at = next.indexOf(category);
        next.splice(at, 1);
        next.splice(at + by, 0, category);
        orderCategories(next);
    }

    async function deleteCategory(category, count) {
        const message = count
            ? `Delete "${category.name}"? Its ${count} to-do${count === 1 ? '' : 's'} will move to the bottom of your list, out of any category.`
            : `Delete "${category.name}"?`;
        if (!confirm(message)) return;
        await api.delete(`/api/todo-categories/${category.id}`);
        reloadTodos();
    }

    async function clearDone() {
        if (!confirm(`Clear ${todos.done.length} done to-do${todos.done.length === 1 ? '' : 's'}? This can't be undone.`)) return;
        await api.delete('/api/todos/done');
        reloadTodos();
    }


    return (
        <div className="todo-list">
            <div className="page-section">
                {sections.map(({ category, todos: items }, i) => {
                    const categoryId = category?.id ?? null;
                    return (
                        <div key={categoryId ?? 'none'} className="todo-section" style={category ? colorStyle(category) : undefined}>
                            {!category && categories.length > 0 && (
                                <div
                                    data-drop="category:none"
                                    aria-hidden="true"
                                    className={`todo-category todo-category--plain${dropMark('category:none') ? ` todo-drop todo-drop--${dropMark('category:none')}` : ''}`}
                                />
                            )}
                            {category && (
                                <CategoryHeader
                                    category={category}
                                    count={items.length}
                                    first={i === 0}
                                    last={i === categories.length - 1}
                                    onChange={(changes) => changeCategory(category, changes)}
                                    onPreview={(changes) => setCategories((all) => all.map((c) => (c.id === category.id ? { ...c, ...changes } : c)))}
                                    onShift={(by) => shiftCategory(category, by)}
                                    onDelete={() => deleteCategory(category, items.length)}
                                    drag={{
                                        dragging: drag?.kind === 'category' && drag.id === category.id,
                                        drop: dropMark(`category:${category.id}`),
                                        onHandleDown: (e) => start(e, 'category', category.id),
                                    }}
                                />
                            )}
                            {!category?.collapsed && (
                                <div className="subtask-list">
                                    {items.map((todo) => (
                                        <TodoRow
                                            key={todo.id}
                                            todo={todo}
                                            userId={userId}
                                            categories={categories}
                                            onStar={() => star(todo)}
                                            onMove={(to) => moveTodo(todo, to)}
                                            drag={{
                                                dragging: drag?.kind === 'todo' && drag.id === todo.id,
                                                drop: dropMark(`todo:${todo.id}`),
                                                onHandleDown: (e) => start(e, 'todo', todo.id),
                                            }}
                                        />
                                    ))}
                                    <AddTodo
                                        categoryId={categoryId}
                                        userId={userId}
                                        autoFocus={categories.length === 0}
                                        placeholder={category ? `Add to ${category.name}…` : open.length ? 'Add another…' : 'Add a to-do…'}
                                        dropOver={drag?.kind === 'todo' && drag.over === `end:${categoryId ?? 'none'}`}
                                    />
                                </div>
                            )}
                        </div>
                    );
                })}
                <AddCategory />
                <p className="form-hint">Only you can see this list.</p>
            </div>

            {todos.done.length > 0 && (
                <div>
                    <div className="form-panel__header">
                        <div className="section-label section-label--flush">Done</div>
                        <button type="button" onClick={clearDone} className="text-action text-action--sm">Clear done</button>
                    </div>
                    <div className="subtask-list">
                        {todos.done.map((todo) => <TodoRow key={todo.id} todo={todo} userId={userId} category={byId[todo.todo_category_id]} />)}
                    </div>
                </div>
            )}
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
                    <div className="my-work-projects__last">{formatShortDate(project.last_worked_on)}</div>
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

            {tab === 'To-do' && <TodoList todos={todos} userId={props.auth.user.id} />}
        </AppLayout>
    );
}

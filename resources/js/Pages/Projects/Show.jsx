import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, DotsSixVertical, Eye, GearSix, DownloadSimple, Paperclip, PencilSimple, Plus, Trash, Users, X } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import MetricCard from '../../Components/MetricCard';
import Badge from '../../Components/Badge';
import RichTextEditor from '../../Components/RichTextEditor';
import RichTextView from '../../Components/RichTextView';
import Avatar from '../../Components/Avatar';
import { NewThreadForm, ThreadView, isSameActor, threadParticipantActors } from '../../Components/MessagesPanel';
import { ProjectStatusBadge, TaskStatusBadge, InvoiceStatusBadge, ProposalStatusBadge, ExpenseStatusBadge, TimeEntryStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, formatDateTime, formatFileSize, invoiceTotal, todayInAppTimezone } from '../../lib/format';
import { addDays, daysBetween, formatLength, formatRange } from '../../lib/scheduleDates';
import ScheduleChart from '../../Components/schedule/ScheduleChart';
import { todayLocal } from '../../lib/paymentTerms';
import { api } from '../../lib/api';
import { isBlankRichText, toRichText } from '../../lib/richText';
import PageHeader from '../../Components/PageHeader';
import StarButton, { useFavorites } from '../../Components/StarButton';
import { useUnreadThreads } from '../../lib/unreadThreads';
import Drawer, { DrawerByline, DrawerDate } from '../../Components/Drawer';
import ProposalDrawer from '../../Components/ProposalDrawer';
import { useInvoiceDrawer } from '../../Components/InvoiceDrawer';
import NewInvoiceDrawer from '../../Components/NewInvoiceDrawer';
import TabBar from '../../Components/TabBar';
import TabToolbar from '../../Components/TabToolbar';
import { useRememberedTab } from '../../lib/useRememberedTab';
import AutoResizeTextarea from '../../Components/AutoResizeTextarea';
import Toggle from '../../Components/Toggle';
import RowActions from '../../Components/RowActions';
import TimeEntryDrawer, { NewTimeEntryDrawer } from '../../Components/TimeEntryDrawer';
import { Field } from '../../Components/client/ClientFields';
import ContactCards from '../../Components/client/ContactCards';
import ActionMenu from '../../Components/ActionMenu';
import { PROJECT_STATUS_OPTIONS } from '../../Components/ProjectsTable';
import ProposalView from '../../Components/ProposalView';
import { hasPermission } from '../../lib/permissions';

// The team isn't a tab: it opens in a drawer from the header (TeamDrawer).
const ALL_TABS = ['Schedule', 'Tasks', 'Notes', 'Messages', 'Time', 'Proposals', 'Billing', 'Expenses'];
// The money tabs, each by its permission (`can`, from the server). Proposals
// is everyone's: those without the permission see the approved ones.
const TAB_PERMISSIONS = { Billing: 'invoices', Expenses: 'expenses' };

function reload() {
    router.reload({ only: ['project'] });
}


// Debounced save for a field that changes on every keystroke (rich text).
// queue() restarts the timer, flush() saves now, cancel() drops it. A
// pending save is flushed on unmount, so closing mid-type keeps the last
// keystrokes. The args are captured at queue time, so a save always lands
// on the record that was being edited.
function useDebouncedSave(save, delay = 600) {
    const timeout = useRef(null);
    const pending = useRef(null);
    const saveRef = useRef(save);
    saveRef.current = save;

    function flush() {
        clearTimeout(timeout.current);
        const args = pending.current;
        if (!args) return;
        pending.current = null;
        saveRef.current(...args);
    }

    function queue(...args) {
        pending.current = args;
        clearTimeout(timeout.current);
        timeout.current = setTimeout(flush, delay);
    }

    function cancel() {
        clearTimeout(timeout.current);
        pending.current = null;
    }

    // flush reads only refs, so the first render's copy is safe here.
    useEffect(() => flush, []);

    return { queue, flush, cancel };
}

// The project's settings, from the header's gear: name, status, contact,
// PO number and description. Saved together on the button.
function ProjectSettingsDrawer({ project, onClose }) {
    const [form, setForm] = useState({
        name: project.name,
        status: project.status,
        contact_id: project.contact_id ? String(project.contact_id) : '',
        po_number: project.po_number ?? '',
        description: project.description ?? '',
    });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const contacts = project.company.contacts || [];

    function field(name) {
        return { value: form[name], onChange: (e) => setForm({ ...form, [name]: e.target.value }) };
    }

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setError('');
        try {
            await api.patch(`/api/projects/${project.id}`, {
                name: form.name,
                status: form.status,
                contact_id: form.contact_id || null,
                po_number: form.po_number.trim() || null,
                description: form.description.trim() || null,
            });
            reload();
            onClose();
        } catch (err) {
            setError(err.message || 'Could not save these settings.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">Project settings</h2>
            <form onSubmit={save}>
                <div className="drawer__section drawer__section--divided">
                    <div className="section-label section-label--tight">Name</div>
                    <input required autoFocus {...field('name')} className="input input--xs" />
                </div>
                <div className="form-grid drawer__section">
                    <div>
                        <div className="section-label section-label--tight">Status</div>
                        <select {...field('status')} className="input input--xs">
                            {PROJECT_STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                        </select>
                    </div>
                    <div>
                        <div className="section-label section-label--tight">PO number</div>
                        <input {...field('po_number')} className="input input--xs" />
                    </div>
                    <div className="form-grid__full">
                        <div className="section-label section-label--tight">Contact</div>
                        <select {...field('contact_id')} className="input input--xs">
                            <option value="">No contact</option>
                            {contacts.map((c) => (
                                <option key={c.id} value={c.id}>{c.name}{c.email ? ` (${c.email})` : ''}</option>
                            ))}
                        </select>
                    </div>
                </div>
                <div className="drawer__section">
                    <div className="section-label">Description</div>
                    <AutoResizeTextarea {...field('description')} placeholder="What this project is…" className="input" />
                    <div className="form-hint form-hint--attached">Clients see this on the project in their portal.</div>
                </div>

                {error && <div className="form-message form-message--error drawer__section">{error}</div>}

                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>Save settings</Button>
                </div>
            </form>
        </Drawer>
    );
}

// Under the header, as on the client page: the project's details in
// columns, its description, then the metric cards. Money figures only
// for managers (the only ones sent invoices).
function ProjectSummary({ project, proposedHours }) {
    const sumHours = (entries) => entries.reduce((s, e) => s + parseFloat(e.hours), 0);
    const totalHours = sumHours(project.time_entries);
    const billableHours = sumHours(project.time_entries.filter((e) => e.billable));
    // Hours sold in accepted proposals, less the billable time logged.
    const hoursRemaining = proposedHours > 0 ? proposedHours - billableHours : null;
    const doneTasks = project.tasks.filter((t) => t.status === 'done').length;
    const invoices = project.invoices;
    const totalInvoiced = invoices ? invoices.reduce((s, inv) => s + invoiceTotal(inv.items, inv.surcharge), 0) : 0;
    const budget = parseFloat(project.budget) || 0;
    const remaining = budget - totalInvoiced;

    return (
        <>
            <div className={`field-grid page-section${project.description ? '' : ' page-section--loose'}`}>
                <Field label="Client">
                    <div className="field-grid__value">
                        <Link href={`/clients/${project.company.id}`} className="link">{project.company.name}</Link>
                    </div>
                </Field>
                <Field label="Contact">
                    {project.contact ? <div className="field-grid__value">{project.contact.name}</div> : null}
                </Field>
                <Field label="PO number">
                    {project.po_number ? <div className="field-grid__value">{project.po_number}</div> : null}
                </Field>
                <Field label="Status">
                    <div><ProjectStatusBadge project={project} /></div>
                </Field>
            </div>
            {project.description && <p className="project-overview__description page-section page-section--loose">{project.description}</p>}

            {/* Work, then money in a row of its own, so the budget figures
                always sit together instead of wrapping apart. */}
            <div className={`metric-grid ${invoices ? 'metric-grid--stacked' : 'metric-grid--loose'}`}>
                <MetricCard label="Tasks" value={`${doneTasks}/${project.tasks.length}`} />
                <MetricCard label="Hours logged" value={`${totalHours}h`} />
                <MetricCard label="Billable hours" value={`${billableHours}h`} />
                <MetricCard
                    label="Hours remaining"
                    value={hoursRemaining === null ? '—' : `${hoursRemaining}h`}
                    // Primary, or danger once the sold hours are used up.
                    tone={hoursRemaining !== null && hoursRemaining < 0 ? 'danger' : 'primary'}
                />
            </div>
            {invoices && (
                <div className="metric-grid metric-grid--one-row metric-grid--loose">
                    {budget > 0 && <MetricCard label="Budget" value={formatCurrency(budget)} />}
                    <MetricCard label="Total invoiced" value={formatCurrency(totalInvoiced)} />
                    {budget > 0 && <MetricCard label="Remaining" value={formatCurrency(remaining)} negative={remaining < 0} tone="neutral" />}
                </div>
            )}
        </>
    );
}

function TaskRow({ task, onChange, onOpen }) {
    async function cycleStatus(e) {
        e.stopPropagation();
        const order = ['todo', 'in_progress', 'done'];
        const next = order[(order.indexOf(task.status) + 1) % order.length];
        await api.patch(`/api/tasks/${task.id}`, { status: next });
        onChange();
    }

    // Assignee and due date read as plain row text -- they're edited in the
    // task's drawer. Only the status badge acts in the row (it cycles).
    return (
        <div onClick={() => onOpen(task.id)} className="grid-row grid-row--action grid-row--link">
            <div className="task-list__title">
                <span className="u-truncate">{task.title}</span>
                {task.visible_to_client && (
                    <Eye className="task-list__client" aria-label="Shown to the client" title="Shown to the client" />
                )}
            </div>
            <div className={`task-list__assignee${task.assignee ? '' : ' task-list__empty'}`}>{task.assignee || 'Unassigned'}</div>
            <div className={`task-list__due${task.due_date ? '' : ' task-list__empty'}`}>{task.due_date ? formatDate(task.due_date) : '—'}</div>
            {/* How many subtasks it has (they're listed in the drawer). */}
            <div className={`task-list__subtasks${task.subtasks?.length ? '' : ' task-list__empty'}`}>{task.subtasks?.length || '—'}</div>
            <div className="task-list__status">
                <button onClick={cycleStatus}>
                    <TaskStatusBadge task={task} />
                </button>
            </div>
            <RowActions
                openLabel="Open task"
                deleteLabel="Delete task"
                confirmMessage={`Delete the task "${task.title}"? This can't be undone.`}
                onDelete={async () => {
                    await api.delete(`/api/tasks/${task.id}`);
                    onChange();
                }}
            />
        </div>
    );
}

const TASK_STATUS_OPTIONS = [
    { value: 'todo', label: 'To do' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'done', label: 'Done' },
];

function SubtaskRow({ subtask, onChange, isDragging, onDragStart, onDragOver, onDrop, onDragEnd }) {
    const [title, setTitle] = useState(subtask.title);

    useEffect(() => setTitle(subtask.title), [subtask.id]);

    async function updateField(field, value) {
        await api.patch(`/api/subtasks/${subtask.id}`, { [field]: value });
        onChange();
    }

    function toggleDone() {
        updateField('status', subtask.status === 'done' ? 'todo' : 'done');
    }

    async function remove() {
        await api.delete(`/api/subtasks/${subtask.id}`);
        onChange();
    }

    return (
        <div
            draggable
            onDragStart={onDragStart}
            onDragOver={onDragOver}
            onDrop={onDrop}
            onDragEnd={onDragEnd}
            className={`subtask-list__item${isDragging ? ' subtask-list__item--dragging' : ''}`}
        >
            <span className="subtask-list__handle">
                <DotsSixVertical size={14} weight="bold" />
            </span>
            <button
                onClick={toggleDone}
                className={`subtask-list__check${subtask.status === 'done' ? ' subtask-list__check--done' : ''}`}
            >
                {subtask.status === 'done' && <Check size={12} weight="bold" />}
            </button>
            <AutoResizeTextarea
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onBlur={() => title !== subtask.title && updateField('title', title)}
                className={`subtask-list__title${subtask.status === 'done' ? ' subtask-list__title--done' : ''}`}
            />
            <button onClick={remove} className="icon-btn icon-btn--danger subtask-list__remove">
                <X />
            </button>
        </div>
    );
}

// The plus opens a draft row at the end of the list, styled like a
// subtask. Enter adds it and opens a fresh draft for the next one; leaving
// the field adds what's typed (like the drawer's other fields, which save
// on blur) and closes the draft; Escape discards it.
function SubtasksSection({ task, onChange }) {
    const [title, setTitle] = useState('');
    const [adding, setAdding] = useState(false);
    const [dragIndex, setDragIndex] = useState(null);
    const subtasks = task.subtasks || [];

    async function addSubtask() {
        const value = title.trim();
        if (!value) return;
        setTitle('');
        await api.post(`/api/tasks/${task.id}/subtasks`, { title: value });
        onChange();
    }

    function handleDraftKeyDown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addSubtask();
        } else if (e.key === 'Escape') {
            // Discard the draft without the drawer's own Escape closing it.
            e.stopPropagation();
            setTitle('');
            setAdding(false);
        }
    }

    function handleDraftBlur() {
        addSubtask();
        setAdding(false);
    }

    async function reorder(fromIndex, toIndex) {
        if (fromIndex === null || fromIndex === toIndex) return;
        const items = [...subtasks];
        const [moved] = items.splice(fromIndex, 1);
        items.splice(toIndex, 0, moved);
        await Promise.all(items.map((s, idx) => api.patch(`/api/subtasks/${s.id}`, { position: idx })));
        onChange();
    }

    return (
        <div className="form-panel">
            <div className="form-panel__header">
                <div className="section-label section-label--flush">Subtasks</div>
                <button onClick={() => setAdding(true)} title="Add subtask" className="icon-btn icon-btn--secondary">
                    <Plus />
                </button>
            </div>
            {subtasks.length === 0 && !adding ? (
                <EmptyState text="No subtasks yet." />
            ) : (
                <div className="subtask-list">
                    {subtasks.map((subtask, idx) => (
                        <SubtaskRow
                            key={subtask.id}
                            subtask={subtask}
                            onChange={onChange}
                            isDragging={dragIndex === idx}
                            onDragStart={() => setDragIndex(idx)}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={() => {
                                reorder(dragIndex, idx);
                                setDragIndex(null);
                            }}
                            onDragEnd={() => setDragIndex(null)}
                        />
                    ))}
                    {adding && (
                        <div className="subtask-list__item subtask-list__item--draft">
                            <span className="subtask-list__handle" aria-hidden="true">
                                <DotsSixVertical size={14} weight="bold" />
                            </span>
                            <span className="subtask-list__check" aria-hidden="true" />
                            <input
                                autoFocus
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                onKeyDown={handleDraftKeyDown}
                                onBlur={handleDraftBlur}
                                placeholder="New subtask"
                                className="subtask-list__title"
                            />
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function FilesSection({ task, onChange }) {
    const inputRef = useRef(null);
    const [uploading, setUploading] = useState(false);
    const files = task.files || [];

    async function handleFileChange(e) {
        const selected = e.target.files[0];
        if (!selected) return;
        setUploading(true);
        try {
            const formData = new FormData();
            formData.append('file', selected);
            await api.postForm(`/api/tasks/${task.id}/files`, formData);
            onChange();
        } finally {
            setUploading(false);
            e.target.value = '';
        }
    }

    async function remove(file) {
        await api.delete(`/api/files/${file.id}`);
        onChange();
    }

    return (
        <div className="form-panel">
            <div className="form-panel__header">
                <div className="section-label section-label--flush">Files</div>
                <button
                    onClick={() => inputRef.current.click()}
                    disabled={uploading}
                    title={uploading ? 'Uploading…' : 'Add file'}
                    className="icon-btn icon-btn--secondary"
                >
                    <Paperclip />
                </button>
                <input ref={inputRef} type="file" onChange={handleFileChange} hidden />
            </div>
            {files.length === 0 ? (
                <EmptyState text="No files yet." />
            ) : (
                <div className="task-files__list">
                    {files.map((file) => (
                        <div
                            key={file.id}
                            className="task-files__item"
                        >
                            <div className="task-files__info">
                                <div className="task-files__name">{file.filename}</div>
                                <div className="task-files__size">{formatFileSize(file.size)}</div>
                            </div>
                            <div className="task-files__actions">
                                <a href={file.url} download={file.filename} title="Download" className="icon-btn icon-btn--secondary task-files__action">
                                    <DownloadSimple />
                                </a>
                                <button
                                    onClick={() => remove(file)}
                                    title="Remove"
                                    className="icon-btn icon-btn--danger task-files__action task-files__action--reveal"
                                >
                                    <X />
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

// The description works like a note's body: formatted text with a pencil
// to open the editor, or straight into the editor while it's empty.
function TaskDrawer({ task, teamNames, isNew, onClose, onChange }) {
    const [title, setTitle] = useState(task.title);
    const [description, setDescription] = useState(() => toRichText(task.description));
    const titleRef = useRef(null);

    // A just-created task opens with its placeholder title selected.
    useEffect(() => {
        if (isNew) titleRef.current?.select();
    }, []);
    const [editingDescription, setEditingDescription] = useState(isBlankRichText(task.description));

    const descriptionSave = useDebouncedSave(async (id, value) => {
        await api.patch(`/api/tasks/${id}`, { description: isBlankRichText(value) ? null : value });
        onChange();
    });

    useEffect(() => {
        if (task) {
            setTitle(task.title);
            setDescription(toRichText(task.description));
            setEditingDescription(isBlankRichText(task.description));
        }
    }, [task?.id]);

    async function updateField(field, value) {
        await api.patch(`/api/tasks/${task.id}`, { [field]: value || null });
        onChange();
    }

    // A boolean, so not through updateField (which turns false into null).
    async function updateVisibility(value) {
        await api.patch(`/api/tasks/${task.id}`, { visible_to_client: value });
        onChange();
    }

    function handleDescriptionChange(value) {
        setDescription(value);
        descriptionSave.queue(task.id, value);
    }

    function finishEditingDescription() {
        descriptionSave.flush();
        setEditingDescription(false);
    }

    return (
        <Drawer onClose={onClose}>
            <DrawerByline>
                <DrawerDate label="Created" date={task.created_at} />
            </DrawerByline>

            <input
                ref={titleRef}
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onBlur={() => title !== task.title && updateField('title', title)}
                className="inline-edit inline-edit--title"
            />

            {/* Grouped on panels like the proposal and invoice forms. */}
            <div className="form-panel">
                <div className="section-label section-label--ruled">Details</div>
                <div className="form-grid">
                    <div>
                        <label className="label">Status</label>
                        <select
                            value={task.status}
                            onChange={(e) => updateField('status', e.target.value)}
                            className="input input--xs"
                        >
                            {TASK_STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="label">Assignee</label>
                        <select
                            value={task.assignee ?? ''}
                            onChange={(e) => updateField('assignee', e.target.value)}
                            className="input input--xs"
                        >
                            <option value="">Unassigned</option>
                            {teamNames.map((name) => <option key={name} value={name}>{name}</option>)}
                        </select>
                    </div>
                    <div className="form-grid__full">
                        <label className="label">Due date</label>
                        <input
                            type="date"
                            value={task.due_date ? task.due_date.slice(0, 10) : ''}
                            onChange={(e) => updateField('due_date', e.target.value)}
                            className="input input--xs"
                        />
                    </div>
                    <div className="form-grid__full">
                        <Toggle
                            checked={task.visible_to_client}
                            onChange={(value) => updateVisibility(value)}
                            label="Show client"
                        />
                        <div className="form-hint form-hint--attached">
                            {task.visible_to_client
                                ? 'This task appears in the client portal.'
                                : 'Internal only. The client can\'t see this task.'}
                        </div>
                    </div>
                </div>
            </div>

            <div className="form-panel">
                <div className="form-panel__header">
                    <div className="section-label section-label--flush">Description</div>
                    {!editingDescription && (
                        <button
                            onClick={() => setEditingDescription(true)}
                            title="Edit description"
                            className="icon-btn icon-btn--edit"
                        >
                            <PencilSimple />
                        </button>
                    )}
                </div>
                {editingDescription ? (
                    <>
                        <RichTextEditor value={description} onChange={handleDescriptionChange} />
                        <div className="form-actions form-actions--spaced">
                            <Button variant="confirm" onClick={finishEditingDescription}>Done</Button>
                        </div>
                    </>
                ) : !isBlankRichText(description) ? (
                    <RichTextView value={description} />
                ) : (
                    <p className="drawer__empty">No description yet.</p>
                )}
            </div>

            <SubtasksSection task={task} onChange={onChange} />

            <FilesSection task={task} onChange={onChange} />
        </Drawer>
    );
}

function TasksTab({ project }) {
    const [creating, setCreating] = useState(false);
    const [newTaskId, setNewTaskId] = useState(null);
    const [selectedTaskId, setSelectedTaskId] = useState(null);
    // Assignable names come from two places: staff actually assigned to the
    // project (real logins, via active_users) and free-text names added for
    // people without one (team_names). Both should show up as assignee
    // options here; dedupe in case a name appears in both.
    const teamNames = [...new Set([
        ...(project.active_users || []).map((u) => u.name),
        ...(project.team_names || []),
    ])];
    const selectedTask = project.tasks.find((t) => t.id === selectedTaskId) || null;

    // Like a new note: create it straight away and open its drawer, with
    // the placeholder title selected so typing replaces it. (The API
    // requires a title, hence the placeholder rather than a blank.)
    async function createTask() {
        setCreating(true);
        try {
            const task = await api.post(`/api/projects/${project.id}/tasks`, { title: 'New task' });
            router.reload({
                only: ['project'],
                onSuccess: () => {
                    setNewTaskId(task.id);
                    setSelectedTaskId(task.id);
                },
            });
        } finally {
            setCreating(false);
        }
    }

    function closeTask() {
        setSelectedTaskId(null);
        setNewTaskId(null);
    }

    return (
        <div>
            <TabToolbar addLabel="New task" onAdd={createTask} disabled={creating} />
            <div className="card card--flush">
                {project.tasks.length === 0 ? (
                    <EmptyState text="No tasks yet." />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="task-list__title">Task</div>
                            <div className="task-list__assignee">Assignee</div>
                            <div className="task-list__due">Due date</div>
                            <div className="task-list__subtasks">Subtasks</div>
                            <div className="task-list__status">Status</div>
                            <div />
                        </div>
                        {project.tasks.map((task) => (
                            <TaskRow
                                key={task.id}
                                task={task}
                                onChange={reload}
                                onOpen={setSelectedTaskId}
                            />
                        ))}
                    </>
                )}
            </div>

            {selectedTask && (
                <TaskDrawer
                    task={selectedTask}
                    teamNames={teamNames}
                    isNew={selectedTask.id === newTaskId}
                    onClose={closeTask}
                    onChange={reload}
                />
            )}
        </div>
    );
}

// A schedule item in the drawer: create (no `item`) or edit. Saves on the
// button rather than per field, since the two dates are checked together.
function ScheduleItemDrawer({ project, item, onClose }) {
    const lastEnd = (project.schedule_items || []).reduce((max, i) => (i.ends_on > max ? i.ends_on : max), '');
    const defaultStart = lastEnd ? addDays(lastEnd, 1) : todayInAppTimezone();
    const [form, setForm] = useState(() => (item
        ? { title: item.title, description: item.description ?? '', starts_on: item.starts_on, ends_on: item.ends_on }
        : { title: '', description: '', starts_on: defaultStart, ends_on: addDays(defaultStart, 6) }));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function setStart(value) {
        // Keep the item's length when its start moves.
        const length = form.starts_on && form.ends_on ? daysBetween(form.starts_on, form.ends_on) : 7;
        setForm({ ...form, starts_on: value, ends_on: value ? addDays(value, length - 1) : form.ends_on });
    }

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setError('');
        try {
            const payload = { ...form, description: form.description || null };
            if (item) {
                await api.patch(`/api/schedule-items/${item.id}`, payload);
            } else {
                await api.post(`/api/projects/${project.id}/schedule-items`, payload);
            }
            reload();
            onClose();
        } catch (err) {
            setError(err.message || 'Could not save this item.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">{item ? 'Edit schedule item' : 'New schedule item'}</h2>
            <form onSubmit={save}>
                <div className="drawer__section drawer__section--divided">
                    <div className="section-label section-label--tight">Title</div>
                    <input
                        required
                        autoFocus
                        placeholder="e.g. Discovery"
                        value={form.title}
                        onChange={(e) => setForm({ ...form, title: e.target.value })}
                        className="input input--xs"
                    />
                </div>
                <div className="form-grid drawer__section">
                    <div>
                        <div className="section-label section-label--tight">Start</div>
                        <input required type="date" value={form.starts_on} onChange={(e) => setStart(e.target.value)} className="input input--xs" />
                    </div>
                    <div>
                        <div className="section-label section-label--tight">End</div>
                        <input
                            required
                            type="date"
                            min={form.starts_on}
                            value={form.ends_on}
                            onChange={(e) => setForm({ ...form, ends_on: e.target.value })}
                            className="input input--xs"
                        />
                    </div>
                    {form.starts_on && form.ends_on && form.ends_on >= form.starts_on && (
                        <div className="form-hint form-grid__full">{formatLength(form.starts_on, form.ends_on)}</div>
                    )}
                </div>
                <div className="drawer__section">
                    <div className="section-label">Description</div>
                    <AutoResizeTextarea
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        placeholder="What happens in this phase…"
                        className="input"
                    />
                </div>

                {error && <div className="form-message form-message--error drawer__section">{error}</div>}

                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>{item ? 'Save changes' : 'Add to schedule'}</Button>
                </div>
            </form>
        </Drawer>
    );
}

// `drag` wires the row into the list's drag-to-reorder: the row is only
// draggable while its handle is held, so a click still opens the drawer.
function ScheduleRow({ item, onOpen, drag }) {
    return (
        <div
            onClick={() => onOpen(item)}
            draggable={drag.armed}
            onDragStart={drag.onDragStart}
            onDragOver={drag.onDragOver}
            onDrop={drag.onDrop}
            onDragEnd={drag.onDragEnd}
            className={`grid-row grid-row--action grid-row--link${drag.dragging ? ' project-schedule__row--dragging' : ''}`}
        >
            <div className="project-schedule__title">
                <span
                    className="project-schedule__handle"
                    title="Drag to reorder"
                    onClick={(e) => e.stopPropagation()}
                    onPointerDown={drag.onArm}
                    onPointerUp={drag.onDisarm}
                >
                    <DotsSixVertical size={14} weight="bold" />
                </span>
                <div className="project-schedule__text">
                    <span className="u-truncate">{item.title}</span>
                    {item.description && <span className="project-schedule__description u-truncate">{item.description}</span>}
                </div>
            </div>
            <div className="project-schedule__dates">{formatRange(item.starts_on, item.ends_on)}</div>
            <div className="project-schedule__length">{formatLength(item.starts_on, item.ends_on)}</div>
            <RowActions
                openLabel="Open schedule item"
                deleteLabel="Delete schedule item"
                confirmMessage={`Remove "${item.title}" from the schedule? This can't be undone.`}
                onDelete={async () => {
                    await api.delete(`/api/schedule-items/${item.id}`);
                    reload();
                }}
            />
        </div>
    );
}

// The project's schedule: a gantt chart of every item, then the same items
// as a list. A bar, a chart label or a list row opens the item's drawer;
// the + adds one.
function ScheduleTab({ project }) {
    // A local copy so a drag shows its new order immediately; re-synced
    // when the page reloads.
    const [items, setItems] = useState(project.schedule_items || []);
    useEffect(() => setItems(project.schedule_items || []), [project.schedule_items]);
    // 'new' while adding, an item while editing, null when closed.
    const [editing, setEditing] = useState(null);
    const [armedId, setArmedId] = useState(null);
    const [dragId, setDragId] = useState(null);

    async function moveItem(fromId, toId) {
        if (fromId === toId) return;
        const next = [...items];
        const [moved] = next.splice(next.findIndex((i) => i.id === fromId), 1);
        next.splice(next.findIndex((i) => i.id === toId), 0, moved);
        setItems(next);
        try {
            await api.put(`/api/projects/${project.id}/schedule-items/order`, { ids: next.map((i) => i.id) });
        } finally {
            reload();
        }
    }

    function dragProps(item) {
        return {
            armed: armedId === item.id,
            dragging: dragId === item.id,
            onArm: () => setArmedId(item.id),
            onDisarm: () => setArmedId(null),
            onDragStart: () => setDragId(item.id),
            onDragOver: (e) => e.preventDefault(),
            onDrop: () => moveItem(dragId, item.id),
            onDragEnd: () => {
                setDragId(null);
                setArmedId(null);
            },
        };
    }
    const first = items.reduce((min, i) => (!min || i.starts_on < min ? i.starts_on : min), '');
    const last = items.reduce((max, i) => (i.ends_on > max ? i.ends_on : max), '');

    return (
        <div>
            <TabToolbar
                summary={items.length > 0 && (
                    <div className="toolbar__summary">
                        <span className="toolbar__figure">{formatRange(first, last)}</span>
                        {' · '}
                        {formatLength(first, last)}
                    </div>
                )}
                addLabel="New schedule item"
                onAdd={() => setEditing('new')}
            />

            {items.length === 0 ? (
                <div className="card card--flush">
                    <EmptyState text="No schedule yet. Add the project's phases or milestones to build the timeline." />
                </div>
            ) : (
                <>
                    <div className="card card--flush page-section">
                        <ScheduleChart items={items} onOpen={setEditing} />
                    </div>
                    <div className="card card--flush">
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="project-schedule__title">Item</div>
                            <div className="project-schedule__dates">Dates</div>
                            <div className="project-schedule__length">Length</div>
                            <div />
                        </div>
                        {items.map((item) => <ScheduleRow key={item.id} item={item} onOpen={setEditing} drag={dragProps(item)} />)}
                    </div>
                </>
            )}

            {editing && (
                <ScheduleItemDrawer
                    key={editing === 'new' ? 'new' : editing.id}
                    project={project}
                    item={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

function NoteRow({ note, onOpen }) {
    return (
        <div onClick={() => onOpen(note.id)} className="grid-row grid-row--action grid-row--link">
            <div className="note-list__lead">
                {note.user && <Avatar name={note.user.name} avatarUrl={note.user.avatar_url} id={note.user.id} size={24} />}
                <span className="note-list__title">{note.title || 'Untitled note'}</span>
            </div>
            <div className="note-list__author">{note.user?.name ?? '—'}</div>
            <div className="note-list__date">{formatDate(note.updated_at)}</div>
            <RowActions
                openLabel="Open note"
                deleteLabel="Delete note"
                confirmMessage={`Delete the note "${note.title || 'Untitled note'}"? This can't be undone.`}
                onDelete={async () => {
                    await api.delete(`/api/notes/${note.id}`);
                    reload();
                }}
            />
        </div>
    );
}

// A note opens read-only (its body rendered as formatted text) unless it's
// still empty, e.g. just created -- then it opens straight into the editor.
// The pencil action switches an existing note into the editor; Done
// switches back. The title stays inline-editable either way.
function NoteDrawer({ note, onClose, onChange }) {
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [editing, setEditing] = useState(isBlankRichText(note.body));

    // Local state already renders each keystroke -- debounce the network
    // save (and the resulting project reload) so typing doesn't fire a
    // request, and a resync, on every keystroke.
    const bodySave = useDebouncedSave(async (id, value) => {
        await api.patch(`/api/notes/${id}`, { body: isBlankRichText(value) ? null : value });
        onChange();
    });

    useEffect(() => {
        if (note) {
            setTitle(note.title ?? '');
            setBody(note.body ?? '');
            setEditing(isBlankRichText(note.body));
        }
    }, [note?.id]);

    async function updateField(field, value) {
        await api.patch(`/api/notes/${note.id}`, { [field]: value || null });
        onChange();
    }

    function handleBodyChange(value) {
        setBody(value);
        bodySave.queue(note.id, value);
    }

    async function remove() {
        // Drop any pending body save -- the note is about to be deleted.
        bodySave.cancel();
        await api.delete(`/api/notes/${note.id}`);
        onClose();
        onChange();
    }

    function finishEditing() {
        bodySave.flush();
        setEditing(false);
    }

    return (
        <Drawer
            onClose={onClose}
            actions={
                <>
                    {!editing && (
                        <button onClick={() => setEditing(true)} title="Edit note" className="icon-btn icon-btn--edit drawer__action">
                            <PencilSimple />
                        </button>
                    )}
                    <button onClick={remove} title="Delete note" className="icon-btn icon-btn--danger drawer__action">
                        <Trash />
                    </button>
                </>
            }
        >
            <DrawerByline>
                {note.user && (
                    <>
                        <Avatar name={note.user.name} avatarUrl={note.user.avatar_url} id={note.user.id} size={20} />
                        <span className="note-drawer__author">{note.user.name}</span>
                    </>
                )}
                <DrawerDate label="Edited" date={note.updated_at} />
            </DrawerByline>

            <input
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onBlur={() => title !== (note.title ?? '') && updateField('title', title)}
                placeholder="Untitled note"
                className="inline-edit inline-edit--title"
            />

            {editing ? (
                <>
                    <RichTextEditor value={body} onChange={handleBodyChange} />
                    <div className="form-actions form-actions--spaced">
                        <Button variant="confirm" onClick={finishEditing}>Done</Button>
                    </div>
                </>
            ) : !isBlankRichText(body) ? (
                <RichTextView value={body} />
            ) : (
                <p className="drawer__empty">No content yet.</p>
            )}
        </Drawer>
    );
}

function NotesTab({ project }) {
    const [selectedNoteId, setSelectedNoteId] = useState(null);
    const [creating, setCreating] = useState(false);
    const selectedNote = project.notes.find((n) => n.id === selectedNoteId) || null;

    async function createNote() {
        setCreating(true);
        try {
            const note = await api.post(`/api/projects/${project.id}/notes`, {});
            router.reload({
                only: ['project'],
                onSuccess: () => setSelectedNoteId(note.id),
            });
        } finally {
            setCreating(false);
        }
    }

    return (
        <div>
            <TabToolbar addLabel="New note" onAdd={createNote} disabled={creating} />
            <div className="card card--flush">
                {project.notes.length === 0 ? (
                    <EmptyState text="No notes yet." />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="note-list__lead">Note</div>
                            <div className="note-list__author">Author</div>
                            <div className="note-list__date">Updated</div>
                            <div />
                        </div>
                        {project.notes.map((note) => (
                            <NoteRow key={note.id} note={note} onOpen={setSelectedNoteId} />
                        ))}
                    </>
                )}
            </div>

            {selectedNote && (
                <NoteDrawer
                    note={selectedNote}
                    onClose={() => setSelectedNoteId(null)}
                    onChange={reload}
                />
            )}
        </div>
    );
}

function MessageThreadRow({ thread, currentUserId, unread, onOpen }) {
    const participants = threadParticipantActors(thread);
    const amParticipant = participants.some((p) => isSameActor(p, 'user', currentUserId));
    const lastActivity = thread.replies?.length ? thread.replies[thread.replies.length - 1].sent_at : thread.sent_at;

    return (
        <div onClick={() => onOpen(thread)} className="grid-row grid-row--action grid-row--link">
            <div className="project-messages__subject">
                {unread && <span className="unread-dot" title="Unread" />}
                <span className={`u-truncate${unread ? ' unread-subject' : ''}`}>{thread.subject}</span>
                {!amParticipant && <Badge tone="accent" label="Not joined" />}
            </div>
            <div className="project-messages__participants">{participants.map((p) => p.name).join(', ') || '—'}</div>
            <div className="project-messages__date" title={formatDateTime(lastActivity)}>{formatDate(lastActivity)}</div>
            <RowActions openLabel="Open thread" />
        </div>
    );
}

// Threads list, with a thread (and a new message) opening in a drawer.
// Built from MessagesPanel's pieces; the client portal still uses the
// all-in-one MessagesPanel.
function MessagesTab({ project, unread }) {
    const { props } = usePage();
    const currentUser = props.auth?.user;
    const [composing, setComposing] = useState(false);
    const [openThreadId, setOpenThreadId] = useState(null);
    const threads = project.messages || [];
    const openThread = threads.find((t) => t.id === openThreadId) || null;

    const recipientOptions = [
        ...(project.active_users || [])
            .filter((u) => u.id !== currentUser?.id)
            .map((u) => ({ token: `user:${u.id}`, name: u.name, sublabel: 'Team' })),
        ...(project.company.contacts || [])
            .filter((c) => c.has_portal_access)
            .map((c) => ({ token: `contact:${c.id}`, name: c.name, sublabel: 'Client' })),
    ];

    const endpoints = {
        create: `/api/projects/${project.id}/messages`,
        reply: (id) => `/api/messages/${id}/replies`,
        join: (id) => `/api/messages/${id}/join`,
        update: (id) => `/api/messages/${id}`,
        destroy: (id) => `/api/messages/${id}`,
        react: (id) => `/api/messages/${id}/reactions`,
        attachmentUrl: (id) => `/api/attachments/${id}`,
        attachmentThumbnailUrl: (id) => `/api/attachments/${id}/thumbnail`,
    };

    return (
        <div>
            <TabToolbar addLabel="New message" onAdd={() => setComposing(true)} />
            <div className="card card--flush">
                {threads.length === 0 ? (
                    <EmptyState text="No messages yet." />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="project-messages__subject">Subject</div>
                            <div className="project-messages__participants">With</div>
                            <div className="project-messages__date">Last activity</div>
                            <div />
                        </div>
                        {threads.map((thread) => (
                            <MessageThreadRow
                                key={thread.id}
                                thread={thread}
                                currentUserId={currentUser?.id}
                                unread={unread.isUnread(thread)}
                                onOpen={(t) => {
                                    unread.markRead(t);
                                    setOpenThreadId(t.id);
                                }}
                            />
                        ))}
                    </>
                )}
            </div>

            {openThread && (
                <Drawer onClose={() => setOpenThreadId(null)}>
                    <ThreadView
                        thread={openThread}
                        currentActorType="user"
                        currentActorId={currentUser?.id}
                        endpoints={endpoints}
                        onChange={reload}
                        bare
                    />
                </Drawer>
            )}

            {composing && (
                <Drawer onClose={() => setComposing(false)}>
                    <h2 className="drawer__title">New message</h2>
                    <NewThreadForm
                        recipientOptions={recipientOptions}
                        endpoints={endpoints}
                        onCreate={() => { setComposing(false); reload(); }}
                        onCancel={() => setComposing(false)}
                        bare
                    />
                </Drawer>
            )}
        </div>
    );
}

function TimeEntryRow({ entry, canDelete, onOpen }) {
    return (
        <div onClick={() => onOpen(entry.id)} className="grid-row grid-row--action grid-row--link">
            <div className="time-list__date">{formatDate(entry.date)}</div>
            <div className="time-list__who">
                {entry.user && <Avatar name={entry.user.name} avatarUrl={entry.user.avatar_url} id={entry.user.id} size={24} />}
                <span className="u-truncate">{entry.user?.name ?? '—'}</span>
            </div>
            <div className="time-list__hours time-list__hours--narrow">{entry.hours}h</div>
            <div className="time-list__note">
                <span className="time-list__text">
                    {[entry.service?.name, entry.task ? entry.task.title : entry.note].filter(Boolean).join(' · ') || '—'}
                </span>
            </div>
            <div className="time-list__status">
                <TimeEntryStatusBadge entry={entry} />
            </div>
            <RowActions
                openLabel="Open entry"
                deleteLabel="Delete entry"
                confirmMessage={`Delete the ${entry.hours}h entry from ${formatDate(entry.date)}? This can't be undone.`}
                onDelete={canDelete ? async () => {
                    await api.delete(`/api/time-entries/${entry.id}`);
                    reload();
                } : null}
            />
        </div>
    );
}

function TimeTab({ project, timeServices }) {
    const currentUser = usePage().props.auth?.user;
    // Logging and editing anyone's time takes Manage projects.
    const canLogForOthers = hasPermission(currentUser, 'manage_projects');
    // Whose time they can log: the project's team, plus themselves (they
    // needn't be assigned to log their own time).
    const team = project.active_users || [];
    const teamForLogging = team.some((u) => u.id === currentUser?.id) ? team : [{ id: currentUser?.id, name: currentUser?.name }, ...team];
    const [creating, setCreating] = useState(false);
    const [selectedEntryId, setSelectedEntryId] = useState(null);
    const selectedEntry = project.time_entries.find((e) => e.id === selectedEntryId) || null;

    return (
        <div>
            <TabToolbar addLabel="Log time" onAdd={() => setCreating(true)} />
            <div className="card card--flush">
                {project.time_entries.length === 0 ? (
                    <EmptyState text="No time logged yet." />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="time-list__date">Date</div>
                            <div className="time-list__who">Team member</div>
                            <div className="time-list__hours time-list__hours--narrow">Hours</div>
                            <div className="time-list__note">Service · Task / Note</div>
                            <div className="time-list__status">Status</div>
                            <div />
                        </div>
                        {project.time_entries.map((entry) => (
                            <TimeEntryRow
                                key={entry.id}
                                entry={entry}
                                // Same rule as the API: a manager, or the person who logged it.
                                canDelete={hasPermission(currentUser, 'manage_projects') || entry.user_id === currentUser?.id}
                                onOpen={setSelectedEntryId}
                            />
                        ))}
                    </>
                )}
            </div>

            {selectedEntry && (
                <TimeEntryDrawer
                    key={selectedEntry.id}
                    entry={selectedEntry}
                    tasks={project.tasks}
                    teamMembers={canLogForOthers ? project.active_users : null}
                    services={timeServices}
                    canEdit={hasPermission(currentUser, 'manage_projects') || selectedEntry.user_id === currentUser?.id}
                    onClose={() => setSelectedEntryId(null)}
                    onChange={reload}
                />
            )}

            {creating && (
                <NewTimeEntryDrawer
                    companies={[project.company]}
                    projects={[project]}
                    fixedProjectId={project.id}
                    teamMembers={canLogForOthers ? teamForLogging : null}
                    services={timeServices}
                    currentUserId={currentUser?.id}
                    onCreated={reload}
                    onClose={() => setCreating(false)}
                />
            )}
        </div>
    );
}

function ProposalRow({ proposal, onOpen, canEdit }) {
    return (
        <div onClick={() => onOpen(proposal.id)} className="grid-row grid-row--action grid-row--link">
            <div className="project-proposals__title">{proposal.title}</div>
            <div className="project-proposals__estimate">
                {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
            </div>
            <div className="project-proposals__status"><ProposalStatusBadge proposal={proposal} /></div>
            <RowActions
                openLabel="Open proposal"
                deleteLabel="Delete proposal"
                confirmMessage={`Delete the proposal "${proposal.title}"? This can't be undone.`}
                // Accepted proposals can't be deleted -- unaccept first.
                onDelete={canEdit && proposal.status !== 'accepted' ? async () => {
                    await api.delete(`/api/proposals/${proposal.id}`);
                    reload();
                } : null}
            />
        </div>
    );
}

// With the Proposals permission: every proposal, to write, send and edit.
// Without: the approved ones (all the server sends), read-only.
function ProposalsTab({ project, services, canEdit }) {
    const [creating, setCreating] = useState(false);
    const [selectedProposalId, setSelectedProposalId] = useState(null);
    const selectedProposal = project.proposals.find((p) => p.id === selectedProposalId) || null;
    // The editor's client list: just this project's client, with this project.
    const companies = [{ ...project.company, projects: [{ id: project.id, name: project.name }] }];
    const drawerProps = {
        companies,
        services,
        presetCompanyId: project.company_id,
        presetProjectId: project.id,
        onChange: reload,
    };

    return (
        <div>
            {canEdit && <TabToolbar addLabel="New proposal" onAdd={() => setCreating(true)} />}
            <div className="card card--flush">
                {project.proposals.length === 0 ? (
                    <EmptyState text={canEdit ? 'No proposals for this project yet.' : 'No approved proposals for this project yet.'} />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="project-proposals__title">Title</div>
                            <div className="project-proposals__estimate">Estimate</div>
                            <div className="project-proposals__status">Status</div>
                            <div />
                        </div>
                        {project.proposals.map((proposal) => (
                            <ProposalRow key={proposal.id} proposal={proposal} onOpen={setSelectedProposalId} canEdit={canEdit} />
                        ))}
                    </>
                )}
            </div>

            {selectedProposal && !canEdit && (
                <ProposalView proposal={selectedProposal} onClose={() => setSelectedProposalId(null)} />
            )}

            {selectedProposal && canEdit && (
                <ProposalDrawer
                    {...drawerProps}
                    proposal={{ ...selectedProposal, project: { id: project.id, name: project.name } }}
                    onClose={() => setSelectedProposalId(null)}
                />
            )}

            {creating && (
                <ProposalDrawer {...drawerProps} proposal={null} onClose={() => setCreating(false)} />
            )}
        </div>
    );
}

function InvoiceRow({ invoice, onOpen, loading }) {
    return (
        <div
            onClick={() => onOpen(invoice.id)}
            aria-busy={loading || undefined}
            className={`grid-row grid-row--action grid-row--link${loading ? ' grid-row--loading' : ''}`}
        >
            <div className="project-billing__number">{invoice.invoice_number}</div>
            <div className="project-billing__issued">{formatDate(invoice.issued_on)}</div>
            <div className="project-billing__total">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge, invoice.tax_rate))}</div>
            <div className="project-billing__status"><InvoiceStatusBadge invoice={invoice} /></div>
            <RowActions
                openLabel="Open invoice"
                deleteLabel="Delete invoice"
                confirmMessage={`Delete invoice #${invoice.invoice_number} (${formatCurrency(invoiceTotal(invoice.items, invoice.surcharge, invoice.tax_rate))})? Its time and expenses go back to unbilled. This can't be undone.`}
                // Paid invoices can't be deleted.
                onDelete={invoice.status !== 'paid' ? async () => {
                    await api.delete(`/api/invoices/${invoice.id}`);
                    reload();
                } : null}
            />
        </div>
    );
}

function BillingTab({ project }) {
    const [creating, setCreating] = useState(false);
    const { openInvoice, loadingId, drawer: invoiceDrawer } = useInvoiceDrawer(reload);
    const budget = parseFloat(project.budget) || 0;
    const totalInvoiced = project.invoices.reduce((s, inv) => s + invoiceTotal(inv.items, inv.surcharge), 0);
    const remaining = budget - totalInvoiced;

    return (
        <div>
            <TabToolbar
                summary={budget > 0 && (
                    <div className="toolbar__summary">
                        Budget <span className="toolbar__figure">{formatCurrency(budget)}</span>
                        {' · '}
                        Remaining <span className={`toolbar__figure${remaining < 0 ? ' toolbar__figure--negative' : ''}`}>{formatCurrency(remaining)}</span>
                    </div>
                )}
                addLabel="New invoice"
                onAdd={() => setCreating(true)}
            />

            <div className="card card--flush">
                {project.invoices.length === 0 ? (
                    <EmptyState text="No invoices for this project yet." />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="project-billing__number">#</div>
                            <div className="project-billing__issued">Issued</div>
                            <div className="project-billing__total">Total</div>
                            <div className="project-billing__status">Status</div>
                            <div />
                        </div>
                        {project.invoices.map((invoice) => (
                            <InvoiceRow
                                key={invoice.id}
                                invoice={invoice}
                                loading={loadingId === invoice.id}
                                onOpen={openInvoice}
                            />
                        ))}
                    </>
                )}
            </div>

            {invoiceDrawer}

            {creating && (
                <NewInvoiceDrawer
                    company={project.company}
                    projects={[project]}
                    initialProjectId={project.id}
                    lockProject
                    onCreated={reload}
                    onClose={() => setCreating(false)}
                />
            )}
        </div>
    );
}

function emptyExpenseForm() {
    return { name: '', amount: '', is_billable: false, markup_percent: '0', date: todayLocal() };
}

// The fields an expense shares between create and edit. `onCommit` fires
// for a field that should save now (a checkbox, a date pick); text and
// number fields commit on blur via `onBlurField`.
function ExpenseFields({ values, disabled, onField, onCommit, onBlurField }) {
    return (
        <>
            <div className="form-grid drawer__section drawer__section--divided">
                <div>
                    <div className="section-label section-label--tight">Amount</div>
                    <input
                        required
                        type="number"
                        min="0.01"
                        step="0.01"
                        value={values.amount}
                        disabled={disabled}
                        onChange={(e) => onField('amount', e.target.value)}
                        onBlur={() => onBlurField?.('amount')}
                        className="input input--xs u-tabular-nums"
                    />
                </div>
                <div>
                    <div className="section-label section-label--tight">Date</div>
                    <input
                        required
                        type="date"
                        value={values.date}
                        disabled={disabled}
                        onChange={(e) => onCommit('date', e.target.value)}
                        className="input input--xs"
                    />
                </div>
            </div>

            <div className="form-grid drawer__section">
                <Toggle
                    checked={values.is_billable}
                    disabled={disabled}
                    onChange={(is_billable) => onCommit('is_billable', is_billable)}
                    label="Billable to this project"
                />
                <div>
                    <div className="section-label section-label--tight">Markup %</div>
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={values.markup_percent}
                        disabled={disabled || !values.is_billable}
                        onChange={(e) => onField('markup_percent', e.target.value)}
                        onBlur={() => onBlurField?.('markup_percent')}
                        className="input input--xs u-tabular-nums"
                    />
                </div>
            </div>
        </>
    );
}

// Create mode: an expense needs a name, amount and date before it can
// exist, so this is a form that saves on submit.
function NewExpenseDrawer({ project, onClose }) {
    const [form, setForm] = useState(emptyExpenseForm);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function setField(field, value) {
        setForm((current) => ({ ...current, [field]: value }));
    }

    async function addExpense(e) {
        e.preventDefault();
        setSaving(true);
        setError('');
        try {
            await api.post('/api/expenses', { ...form, project_id: project.id });
            reload();
            onClose();
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">Add expense</h2>
            <form onSubmit={addExpense}>
                <div className="drawer__section">
                    <div className="section-label section-label--tight">Name</div>
                    <input
                        required
                        autoFocus
                        value={form.name}
                        onChange={(e) => setField('name', e.target.value)}
                        className="input input--xs"
                    />
                </div>
                <ExpenseFields values={form} onField={setField} onCommit={setField} />
                {error && <div className="form-message form-message--error form-message--spaced">{error}</div>}
                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>Add expense</Button>
                </div>
            </form>
        </Drawer>
    );
}

// Edit mode. Fields save as you go, like the other drawers. Once billed,
// the API refuses edits, so the fields go read-only and delete disappears.
function ExpenseDrawer({ expense, onClose, onChange }) {
    const editable = expense.billing_status === 'unbilled';
    const [values, setValues] = useState(() => valuesFrom(expense));
    const [error, setError] = useState('');

    function valuesFrom(e) {
        return {
            name: e.name,
            amount: e.amount,
            is_billable: e.is_billable,
            markup_percent: e.markup_percent ?? '0',
            date: e.date.slice(0, 10),
        };
    }

    useEffect(() => setValues(valuesFrom(expense)), [expense.id]);

    async function save(field, value) {
        setError('');
        try {
            await api.patch(`/api/expenses/${expense.id}`, { [field]: value });
            onChange();
        } catch (err) {
            setError(err.message);
        }
    }

    function setField(field, value) {
        setValues((current) => ({ ...current, [field]: value }));
    }

    function commit(field, value) {
        setField(field, value);
        save(field, value);
    }

    // Save a text/number field on blur, only if it changed.
    function blurField(field) {
        if (String(values[field]) !== String(valuesFrom(expense)[field])) {
            save(field, values[field]);
        }
    }

    async function remove() {
        if (!confirm(`Delete "${expense.name}"?`)) return;
        await api.delete(`/api/expenses/${expense.id}`);
        onClose();
        onChange();
    }

    return (
        <Drawer
            onClose={onClose}
            actions={editable && (
                <button onClick={remove} title="Delete expense" className="icon-btn icon-btn--danger drawer__action">
                    <Trash />
                </button>
            )}
        >
            <DrawerByline>
                <DrawerDate label="Created" date={expense.created_at} />
                <ExpenseStatusBadge expense={expense} />
            </DrawerByline>
            <input
                value={values.name}
                disabled={!editable}
                onChange={(e) => setField('name', e.target.value)}
                onBlur={() => blurField('name')}
                className="inline-edit inline-edit--title"
            />
            {!editable && (
                <p className="form-hint drawer__section">
                    Billed expenses can't be edited. Detach it from its invoice first.
                </p>
            )}
            <ExpenseFields
                values={values}
                disabled={!editable}
                onField={setField}
                onCommit={commit}
                onBlurField={blurField}
            />
            {expense.category && (
                <div className="drawer__section">
                    <div className="section-label section-label--tight">Category</div>
                    <div className="drawer__meta">{expense.category.name}</div>
                </div>
            )}
            {error && <div className="form-message form-message--error">{error}</div>}
        </Drawer>
    );
}

function ExpenseRow({ expense, onOpen }) {
    return (
        <div onClick={() => onOpen(expense.id)} className="grid-row grid-row--action grid-row--link">
            <div className="project-expenses__date">{formatDate(expense.date)}</div>
            <div className="project-expenses__name">{expense.name}</div>
            <div className="project-expenses__status"><ExpenseStatusBadge expense={expense} /></div>
            <div className="project-expenses__amount">{formatCurrency(expense.amount)}</div>
            <RowActions
                openLabel="Open expense"
                deleteLabel="Delete expense"
                confirmMessage={`Delete the expense "${expense.name}"? This can't be undone.`}
                // Billed expenses can't be deleted -- detach from the invoice first.
                onDelete={expense.billing_status === 'unbilled' ? async () => {
                    await api.delete(`/api/expenses/${expense.id}`);
                    reload();
                } : null}
            />
        </div>
    );
}

function ExpensesTab({ project }) {
    const expenses = project.expenses || [];
    const [creating, setCreating] = useState(false);
    const [selectedExpenseId, setSelectedExpenseId] = useState(null);
    const selectedExpense = expenses.find((e) => e.id === selectedExpenseId) || null;
    const total = expenses.reduce((s, e) => s + parseFloat(e.amount), 0);

    return (
        <div>
            <TabToolbar
                summary={
                    <div className="project-expenses__total">
                        Total expenses <span className="project-expenses__total-value">{formatCurrency(total)}</span>
                    </div>
                }
                addLabel="Add expense"
                onAdd={() => setCreating(true)}
            />
            <div className="card card--flush">
                {expenses.length === 0 ? (
                    <EmptyState text="No expenses logged for this project." />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="project-expenses__date">Date</div>
                            <div className="project-expenses__name">Name</div>
                            <div className="project-expenses__status">Status</div>
                            <div className="project-expenses__amount">Amount</div>
                            <div />
                        </div>
                        {expenses.map((expense) => (
                            <ExpenseRow key={expense.id} expense={expense} onOpen={setSelectedExpenseId} />
                        ))}
                    </>
                )}
            </div>

            {selectedExpense && (
                <ExpenseDrawer
                    expense={selectedExpense}
                    onClose={() => setSelectedExpenseId(null)}
                    onChange={reload}
                />
            )}

            {creating && <NewExpenseDrawer project={project} onClose={() => setCreating(false)} />}
        </div>
    );
}

function roleLabel(role) {
    return role ? role.replace('_', ' ').replace(/^./, (c) => c.toUpperCase()) : null;
}

// Add someone to the project: a staff account (with Manage projects --
// assigning staff is a roster decision) or a name for someone without a login.
function AddTeamMemberDrawer({ project, canManageTeam, assignableStaff, onClose }) {
    const assignedIds = (project.active_users || []).map((u) => u.id);
    const available = (assignableStaff || []).filter((staffer) => !assignedIds.includes(staffer.id));
    const [pickId, setPickId] = useState('');
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function run(action) {
        setBusy(true);
        setError('');
        try {
            await action();
            reload();
            onClose();
        } catch (err) {
            setError(err.message || 'Could not add them.');
        } finally {
            setBusy(false);
        }
    }

    function assign(e) {
        e.preventDefault();
        if (!pickId) return;
        run(() => api.post(`/api/projects/${project.id}/assignments`, { user_id: pickId }));
    }

    function addName(e) {
        e.preventDefault();
        const trimmed = name.trim();
        const names = project.team_names || [];
        if (!trimmed || names.includes(trimmed)) return;
        run(() => api.patch(`/api/projects/${project.id}`, { team_names: [...names, trimmed] }));
    }

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">Add to team</h2>

            {canManageTeam && (
                <form onSubmit={assign} className="drawer__section drawer__section--divided">
                    <div className="section-label section-label--tight">Assign staff</div>
                    <div className="inline-form">
                        <select value={pickId} onChange={(e) => setPickId(e.target.value)} className="input input--xs inline-form__grow">
                            <option value="">{available.length ? 'Choose someone…' : 'Everyone is already on this project'}</option>
                            {available.map((staffer) => <option key={staffer.id} value={staffer.id}>{staffer.name}</option>)}
                        </select>
                        <Button type="submit" variant="confirm" disabled={busy || !pickId}>Assign</Button>
                    </div>
                    <div className="form-hint form-hint--attached">They'll see this project in their account.</div>
                </form>
            )}

            <form onSubmit={addName} className="drawer__section">
                <div className="section-label section-label--tight">Add someone without a login</div>
                <div className="inline-form">
                    <input
                        autoFocus={!canManageTeam}
                        placeholder="Name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        className="input input--xs inline-form__grow"
                    />
                    <Button type="submit" variant="confirm" disabled={busy || !name.trim()}>Add</Button>
                </div>
                <div className="form-hint form-hint--attached">Listed on the team and offered as a task assignee.</div>
            </form>

            {error && <div className="form-message form-message--error drawer__section">{error}</div>}
        </Drawer>
    );
}

// Everyone on the project as cards, like the client portal's Team tab:
// assigned staff (photo, name, role, email), then names added for people
// without a login. Opened from the header's team button. The + swaps to
// the Add to team drawer, which comes back here when it closes; a card's
// gear removes someone.
function TeamDrawer({ project, canManageTeam, canEdit, assignableStaff, onClose }) {
    const [adding, setAdding] = useState(false);
    const staff = project.active_users || [];
    const names = project.team_names || [];

    async function unassign(user) {
        if (!confirm(`Remove ${user.name} from this project? They'll keep read-only access to their past work here.`)) return;
        await api.delete(`/api/projects/${project.id}/assignments/${user.id}`);
        reload();
    }

    async function removeName(name) {
        if (!confirm(`Remove ${name} from this project's team?`)) return;
        await api.patch(`/api/projects/${project.id}`, { team_names: names.filter((n) => n !== name) });
        reload();
    }

    const cards = [
        ...staff.map((user) => ({
            id: `user-${user.id}`,
            name: user.name,
            role: roleLabel(user.role),
            email: user.email,
            avatar_url: user.avatar_url,
            remove: canManageTeam ? () => unassign(user) : null,
        })),
        ...names.map((name) => ({
            id: `name-${name}`,
            name,
            role: 'No login',
            remove: canEdit ? () => removeName(name) : null,
        })),
    ];

    if (adding) {
        return (
            <AddTeamMemberDrawer
                project={project}
                canManageTeam={canManageTeam}
                assignableStaff={assignableStaff}
                onClose={() => setAdding(false)}
            />
        );
    }

    return (
        <Drawer
            onClose={onClose}
            actions={canEdit && (
                <button onClick={() => setAdding(true)} title="Add to team" aria-label="Add to team" className="icon-btn icon-btn--secondary drawer__action">
                    <Plus />
                </button>
            )}
        >
            <h2 className="drawer__title">Team</h2>
            {cards.length > 0 && (
                <div className="toolbar toolbar--split page-section--tight">
                    <div className="toolbar__summary">
                        Staff<span className="count">{staff.length}</span>
                        {names.length > 0 && <>{' · '}Without a login<span className="count">{names.length}</span></>}
                    </div>
                </div>
            )}

            {cards.length === 0 ? (
                <div className="card card--flush">
                    <EmptyState text="No one on this project yet." />
                </div>
            ) : (
                <ContactCards
                    contacts={cards}
                    menuFor={(card) => card.remove && (
                        <ActionMenu
                            label={`Settings for ${card.name}`}
                            icon={<GearSix />}
                            items={[{ label: 'Remove from project', onSelect: card.remove, danger: true }]}
                        />
                    )}
                />
            )}
        </Drawer>
    );
}

export default function ProjectsShow({ project, canManageTeam, canEdit, can = {}, assignableStaff, services, timeServices = [], proposedHours = 0 }) {
    const tabs = ALL_TABS.filter((t) => !TAB_PERMISSIONS[t] || can[TAB_PERMISSIONS[t]]);
    const [tab, setTab] = useRememberedTab('project-page-tab', tabs, { param: 'tab' });
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [teamOpen, setTeamOpen] = useState(false);
    const favorites = useFavorites(useMemo(() => [project], [project]));
    // Unread threads: a red count on the Messages tab, a dot on each.
    const unread = useUnreadThreads(project.messages || [], (id) => `/api/messages/${id}/read`);

    return (
        <AppLayout>
            <Head title={project.name} />
            <PageHeader
                back={{ href: '/projects', label: 'Projects' }}
                title={project.name}
                actions={(
                    <>
                        <StarButton starred={favorites.isStarred(project)} onToggle={() => favorites.toggle(project)} className="icon-btn--lg" />
                        <button onClick={() => setTeamOpen(true)} title="Team" aria-label="Team" className="icon-btn icon-btn--secondary icon-btn--lg">
                            <Users />
                        </button>
                        {canEdit && (
                            <button onClick={() => setSettingsOpen(true)} title="Project settings" aria-label="Project settings" className="icon-btn icon-btn--secondary icon-btn--lg">
                                <GearSix />
                            </button>
                        )}
                    </>
                )}
            />
            <ProjectSummary project={project} proposedHours={proposedHours} />

            <TabBar tab={tab} setTab={setTab} tabs={tabs} unread={{ Messages: unread.count }} size="lg" />

            {tab === 'Tasks' && <TasksTab project={project} />}
            {tab === 'Schedule' && <ScheduleTab project={project} />}
            {tab === 'Notes' && <NotesTab project={project} />}
            {tab === 'Messages' && <MessagesTab project={project} unread={unread} />}
            {tab === 'Time' && <TimeTab project={project} timeServices={timeServices} />}
            {tab === 'Proposals' && <ProposalsTab project={project} services={services} canEdit={can.proposals} />}
            {tab === 'Billing' && <BillingTab project={project} />}
            {tab === 'Expenses' && <ExpensesTab project={project} />}

            {settingsOpen && <ProjectSettingsDrawer project={project} onClose={() => setSettingsOpen(false)} />}
            {teamOpen && (
                <TeamDrawer
                    project={project}
                    canManageTeam={canManageTeam}
                    canEdit={canEdit}
                    assignableStaff={assignableStaff}
                    onClose={() => setTeamOpen(false)}
                />
            )}
        </AppLayout>
    );
}

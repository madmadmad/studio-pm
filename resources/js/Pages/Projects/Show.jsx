import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CaretRight, Check, DotsSixVertical, DownloadSimple, Paperclip, PencilSimple, Plus, Trash, X } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import MetricCard from '../../Components/MetricCard';
import Badge from '../../Components/Badge';
import RichTextEditor from '../../Components/RichTextEditor';
import RichTextView from '../../Components/RichTextView';
import Avatar from '../../Components/Avatar';
import Toggle from '../../Components/Toggle';
import { NewThreadForm, ThreadView, isSameActor, threadParticipantActors } from '../../Components/MessagesPanel';
import InvoiceDateFields from '../../Components/InvoiceDateFields';
import { ProjectStatusBadge, TaskStatusBadge, InvoiceStatusBadge, ProposalStatusBadge, ExpenseStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, formatDateTime, formatFileSize, invoiceSubtotal, invoiceTotal } from '../../lib/format';
import { calculateDueDate, todayLocal } from '../../lib/paymentTerms';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import Drawer, { DrawerByline, DrawerDate } from '../../Components/Drawer';
import ProposalEditor, { ProposalActions } from '../../Components/ProposalEditor';
import InvoiceDetail, { InvoiceDueLine } from '../../Components/InvoiceDetail';
import TabBar from '../../Components/TabBar';

const ALL_TABS = ['Overview', 'Tasks', 'Notes', 'Messages', 'Time', 'Proposals', 'Billing', 'Expenses', 'Team'];
const MANAGER_ONLY_TABS = ['Proposals', 'Billing', 'Expenses'];

function reload() {
    router.reload({ only: ['project'] });
}

// True for rich text with no visible text -- Tiptap saves an emptied
// editor as "<p></p>", not an empty string.
function isBlankRichText(html) {
    return !html || html.replace(/<[^>]*>/g, '').trim() === '';
}

// Rich text for the editor/viewer from a stored value that may predate
// rich text (task descriptions were plain text): plain text is escaped
// and each line becomes a paragraph, so its line breaks survive.
function toRichText(value) {
    if (isBlankRichText(value)) return '';
    if (/^\s*</.test(value)) return value;
    const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return value.split('\n').map((line) => `<p>${escape(line)}</p>`).join('');
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

function PoNumberField({ project }) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(project.po_number || '');
    const [saving, setSaving] = useState(false);

    async function save() {
        setSaving(true);
        try {
            await api.patch(`/api/projects/${project.id}`, { po_number: value.trim() || null });
            setEditing(false);
            reload();
        } finally {
            setSaving(false);
        }
    }

    if (editing) {
        return (
            <div className="project-overview__field">
                <input
                    autoFocus
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    placeholder="PO number"
                    className="input input--sm"
                />
                <Button variant="link" onClick={save} disabled={saving}>Save</Button>
                <button onClick={() => setEditing(false)} className="text-action text-action--sm">Cancel</button>
            </div>
        );
    }

    return (
        <div className="project-overview__field project-overview__field--display">
            <span>PO Number: {project.po_number || '—'}</span>
            <Button variant="link" onClick={() => { setValue(project.po_number || ''); setEditing(true); }}>
                Edit
            </Button>
        </div>
    );
}

function ContactField({ project }) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(project.contact_id ? String(project.contact_id) : '');
    const [saving, setSaving] = useState(false);
    const contacts = project.company.contacts || [];

    async function save() {
        setSaving(true);
        try {
            await api.patch(`/api/projects/${project.id}`, { contact_id: value || null });
            setEditing(false);
            reload();
        } finally {
            setSaving(false);
        }
    }

    if (editing) {
        return (
            <div className="project-overview__field">
                <select
                    autoFocus
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    className="input input--sm"
                >
                    <option value="">No contact</option>
                    {contacts.map((contact) => (
                        <option key={contact.id} value={contact.id}>
                            {contact.name}{contact.email ? ` (${contact.email})` : ''}
                        </option>
                    ))}
                </select>
                <Button variant="link" onClick={save} disabled={saving}>Save</Button>
                <button onClick={() => setEditing(false)} className="text-action text-action--sm">Cancel</button>
            </div>
        );
    }

    return (
        <div className="project-overview__field project-overview__field--display">
            <span>Contact: {project.contact?.name || '—'}</span>
            <Button variant="link" onClick={() => { setValue(project.contact_id ? String(project.contact_id) : ''); setEditing(true); }}>
                Edit
            </Button>
        </div>
    );
}

function OverviewTab({ project }) {
    const totalHours = project.time_entries.reduce((s, e) => s + parseFloat(e.hours), 0);
    const unbilledHours = project.time_entries.filter((e) => !e.billed).reduce((s, e) => s + parseFloat(e.hours), 0);
    const doneTasks = project.tasks.filter((t) => t.status === 'done').length;
    const totalInvoiced = project.invoices.reduce((s, inv) => s + invoiceTotal(inv.items, inv.surcharge), 0);
    const budget = parseFloat(project.budget) || 0;
    const remaining = budget - totalInvoiced;

    return (
        <div>
            {project.description && <p className="project-overview__description page-section">{project.description}</p>}
            <ContactField project={project} />
            <PoNumberField project={project} />
            <div className="metric-grid">
                <MetricCard label="Tasks" value={`${doneTasks}/${project.tasks.length}`} />
                <MetricCard label="Hours logged" value={`${totalHours}h`} />
                <MetricCard label="Unbilled hours" value={`${unbilledHours}h`} />
                <MetricCard label="Total invoiced" value={formatCurrency(totalInvoiced)} />
                {budget > 0 && (
                    <>
                        <MetricCard label="Budget" value={formatCurrency(budget)} />
                        <MetricCard label="Remaining" value={formatCurrency(remaining)} negative={remaining < 0} />
                    </>
                )}
            </div>
            {project.team_names?.length > 0 && (
                <div>
                    <div className="project-overview__team-label">Team</div>
                    <div className="cluster">
                        {project.team_names.map((name) => (
                            <Badge key={name} tone="neutral" label={name} />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// The bar above each tab's list: an optional summary on the left (budget,
// totals) and the tab's one add action, a large plus, on the right. Every
// list tab follows the same pattern: this toolbar, a .grid-row list whose
// rows open the item in a drawer, and create/view/edit in that drawer.
function TabToolbar({ summary, addLabel, onAdd, disabled }) {
    return (
        <div className={`toolbar${summary ? ' toolbar--split' : ''} page-section--tight`}>
            {summary}
            <button onClick={onAdd} disabled={disabled} title={addLabel} className="icon-btn icon-btn--secondary icon-btn--lg">
                <Plus />
            </button>
        </div>
    );
}

// The end of a list row: a delete button (when the item can be deleted)
// and the "open" caret. The caret has no handler of its own -- its click
// bubbles to the row, which opens the drawer; it's the keyboard-reachable
// way in. Delete stops its click there, asks first with `confirmMessage`,
// then runs `onDelete` (which should refresh the list). Pass no onDelete
// for an item the API won't delete (accepted, paid, billed), and the
// column keeps its width so rows stay aligned.
function RowActions({ openLabel, deleteLabel, confirmMessage, onDelete }) {
    const [deleting, setDeleting] = useState(false);

    async function remove(e) {
        e.stopPropagation();
        if (deleting || !confirm(confirmMessage)) return;
        setDeleting(true);
        try {
            await onDelete();
        } catch (err) {
            alert(err.message || 'Could not delete this.');
        } finally {
            setDeleting(false);
        }
    }

    return (
        <div className="grid-row__actions">
            {onDelete && (
                <button onClick={remove} disabled={deleting} title={deleteLabel} className="icon-btn icon-btn--danger grid-row__delete">
                    <Trash />
                </button>
            )}
            <button title={openLabel} className="row-action">
                <CaretRight size={14} weight="bold" />
            </button>
        </div>
    );
}

function TaskRow({ task, teamNames, onChange, onOpen }) {
    async function cycleStatus(e) {
        e.stopPropagation();
        const order = ['todo', 'in_progress', 'done'];
        const next = order[(order.indexOf(task.status) + 1) % order.length];
        await api.patch(`/api/tasks/${task.id}`, { status: next });
        onChange();
    }

    async function updateField(field, value) {
        await api.patch(`/api/tasks/${task.id}`, { [field]: value || null });
        onChange();
    }

    // The inline pickers stop their clicks here, so using them doesn't
    // also open the drawer.
    return (
        <div onClick={() => onOpen(task.id)} className="grid-row grid-row--action grid-row--link">
            <div className="task-list__title">
                <span className="u-truncate">{task.title}</span>
            </div>
            <div className="task-list__assignee" onClick={(e) => e.stopPropagation()}>
                <select
                    value={task.assignee ?? ''}
                    onChange={(e) => updateField('assignee', e.target.value)}
                    className="task-list__control"
                >
                    <option value="">Unassigned</option>
                    {teamNames.map((name) => <option key={name} value={name}>{name}</option>)}
                </select>
            </div>
            <div className="task-list__due" onClick={(e) => e.stopPropagation()}>
                <input
                    type="date"
                    value={task.due_date ? task.due_date.slice(0, 10) : ''}
                    onChange={(e) => updateField('due_date', e.target.value)}
                    className="task-list__control task-list__control--date"
                />
            </div>
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

function AutoResizeTextarea({ value, className, ...props }) {
    const ref = useRef(null);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${el.scrollHeight}px`;
    }, [value]);

    return (
        <textarea
            ref={ref}
            value={value}
            rows={1}
            className={`autosize ${className}`}
            {...props}
        />
    );
}

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
        <div className="drawer__section">
            <div className="drawer__section-header">
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
        <div className="drawer__section">
            <div className="drawer__section-header">
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

            <div className="form-grid drawer__section drawer__section--divided">
                <div>
                    <div className="section-label section-label--tight">Status</div>
                    <select
                        value={task.status}
                        onChange={(e) => updateField('status', e.target.value)}
                        className="input input--xs"
                    >
                        {TASK_STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                    </select>
                </div>
                <div>
                    <div className="section-label section-label--tight">Assignee</div>
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
                    <div className="section-label section-label--tight">Due date</div>
                    <input
                        type="date"
                        value={task.due_date ? task.due_date.slice(0, 10) : ''}
                        onChange={(e) => updateField('due_date', e.target.value)}
                        className="input input--xs"
                    />
                </div>
            </div>

            <div className="drawer__section">
                <div className="drawer__section-header">
                    <div className="section-label section-label--flush">Description</div>
                    {!editingDescription && (
                        <button
                            onClick={() => setEditingDescription(true)}
                            title="Edit description"
                            className="icon-btn icon-btn--secondary"
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
                            <div className="task-list__status">Status</div>
                            <div />
                        </div>
                        {project.tasks.map((task) => (
                            <TaskRow
                                key={task.id}
                                task={task}
                                teamNames={teamNames}
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
                        <button onClick={() => setEditing(true)} title="Edit note" className="icon-btn icon-btn--secondary drawer__action">
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

function MessageThreadRow({ thread, currentUserId, onOpen }) {
    const participants = threadParticipantActors(thread);
    const amParticipant = participants.some((p) => isSameActor(p, 'user', currentUserId));
    const lastActivity = thread.replies?.length ? thread.replies[thread.replies.length - 1].sent_at : thread.sent_at;

    return (
        <div onClick={() => onOpen(thread.id)} className="grid-row grid-row--action grid-row--link">
            <div className="project-messages__subject">
                <span className="u-truncate">{thread.subject}</span>
                {!amParticipant && <Badge tone="watermelon" label="Not joined" />}
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
function MessagesTab({ project }) {
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
                                onOpen={setOpenThreadId}
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
                        currentActorRole={currentUser?.role}
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
            <div className="time-list__hours">{entry.hours}h</div>
            <div className="time-list__detail">
                <span className="time-list__text">{entry.task ? entry.task.title : entry.note || '—'}</span>
            </div>
            <div className="time-list__status">
                {entry.billed ? <Badge tone="fern" label="Billed" /> : <Badge tone="neutral" label="Unbilled" />}
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

function TimeEntryDrawer({ entry, tasks, onClose, onChange }) {
    const [hours, setHours] = useState('');
    const [note, setNote] = useState('');

    useEffect(() => {
        if (entry) {
            setHours(entry.hours);
            setNote(entry.note ?? '');
        }
    }, [entry?.id]);

    async function updateField(field, value) {
        await api.patch(`/api/time-entries/${entry.id}`, { [field]: value });
        onChange();
    }

    async function remove() {
        await api.delete(`/api/time-entries/${entry.id}`);
        onClose();
        onChange();
    }

    return (
        <Drawer
            onClose={onClose}
            actions={
                <button onClick={remove} title="Delete entry" className="icon-btn icon-btn--danger drawer__action">
                    <Trash />
                </button>
            }
        >
            <DrawerByline>
                <DrawerDate label="Created" date={entry.created_at} />
                {entry.billed ? <Badge tone="fern" label="Billed" /> : <Badge tone="neutral" label="Unbilled" />}
            </DrawerByline>
            {/* An entry has no name of its own -- it's titled by its task. */}
            <h2 className="drawer__title">{entry.task?.title || 'Time entry'}</h2>

            <div className="form-grid drawer__section drawer__section--divided">
                <div>
                    <div className="section-label section-label--tight">Date</div>
                    <input
                        type="date"
                        value={entry.date.slice(0, 10)}
                        onChange={(e) => updateField('date', e.target.value)}
                        className="input input--xs"
                    />
                </div>
                <div>
                    <div className="section-label section-label--tight">Hours</div>
                    <input
                        type="number"
                        min="0.25"
                        step="0.25"
                        value={hours}
                        onChange={(e) => setHours(e.target.value)}
                        onBlur={() => Number(hours) !== Number(entry.hours) && updateField('hours', hours)}
                        className="input input--xs u-tabular-nums"
                    />
                </div>
            </div>

            <div className="drawer__section">
                <div className="section-label section-label--tight">Task</div>
                <select
                    value={entry.task_id ?? ''}
                    onChange={(e) => updateField('task_id', e.target.value || null)}
                    className="input input--xs"
                >
                    <option value="">No task</option>
                    {tasks.map((t) => <option key={t.id} value={t.id}>{t.title}</option>)}
                </select>
            </div>

            <div className="drawer__section">
                <div className="section-label">Note</div>
                <AutoResizeTextarea
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    onBlur={() => note !== (entry.note ?? '') && updateField('note', note)}
                    placeholder="Add a note…"
                    className="input"
                />
            </div>

            <label className="choice">
                <input
                    type="checkbox"
                    checked={entry.billable}
                    disabled={entry.billed}
                    onChange={(e) => updateField('billable', e.target.checked)}
                />
                Billable
            </label>
        </Drawer>
    );
}

// Create mode for a time entry. Unlike a note or task, an entry can't
// exist half-filled (the API requires a date and hours), so this is a form
// that saves on submit rather than a record created up front.
function NewTimeEntryDrawer({ project, onClose }) {
    const [form, setForm] = useState({ date: todayLocal(), task_id: '', hours: '', note: '' });
    const [saving, setSaving] = useState(false);

    async function logTime(e) {
        e.preventDefault();
        setSaving(true);
        try {
            await api.post('/api/time-entries', {
                company_id: project.company_id,
                project_id: project.id,
                task_id: form.task_id || null,
                date: form.date,
                hours: form.hours,
                note: form.note,
            });
            reload();
            onClose();
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">Log time</h2>
            <form onSubmit={logTime}>
                <div className="form-grid drawer__section drawer__section--divided">
                    <div>
                        <div className="section-label section-label--tight">Date</div>
                        <input
                            required
                            type="date"
                            value={form.date}
                            onChange={(e) => setForm({ ...form, date: e.target.value })}
                            className="input input--xs"
                        />
                    </div>
                    <div>
                        <div className="section-label section-label--tight">Hours</div>
                        <input
                            required
                            autoFocus
                            type="number"
                            min="0.25"
                            step="0.25"
                            value={form.hours}
                            onChange={(e) => setForm({ ...form, hours: e.target.value })}
                            className="input input--xs u-tabular-nums"
                        />
                    </div>
                </div>

                <div className="drawer__section">
                    <div className="section-label section-label--tight">Task</div>
                    <select
                        value={form.task_id}
                        onChange={(e) => setForm({ ...form, task_id: e.target.value })}
                        className="input input--xs"
                    >
                        <option value="">No task</option>
                        {project.tasks.map((t) => <option key={t.id} value={t.id}>{t.title}</option>)}
                    </select>
                </div>

                <div className="drawer__section">
                    <div className="section-label">Note</div>
                    <AutoResizeTextarea
                        value={form.note}
                        onChange={(e) => setForm({ ...form, note: e.target.value })}
                        placeholder="Add a note…"
                        className="input"
                    />
                </div>

                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>Log time</Button>
                </div>
            </form>
        </Drawer>
    );
}

function TimeTab({ project }) {
    const currentUser = usePage().props.auth?.user;
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
                            <div className="time-list__hours">Hours</div>
                            <div className="time-list__detail">Task / Note</div>
                            <div className="time-list__status">Status</div>
                            <div />
                        </div>
                        {project.time_entries.map((entry) => (
                            <TimeEntryRow
                                key={entry.id}
                                entry={entry}
                                // Same rule as the API: a manager, or the person who logged it.
                                canDelete={currentUser?.role === 'manager' || entry.user_id === currentUser?.id}
                                onOpen={setSelectedEntryId}
                            />
                        ))}
                    </>
                )}
            </div>

            {selectedEntry && (
                <TimeEntryDrawer
                    entry={selectedEntry}
                    tasks={project.tasks}
                    onClose={() => setSelectedEntryId(null)}
                    onChange={reload}
                />
            )}

            {creating && <NewTimeEntryDrawer project={project} onClose={() => setCreating(false)} />}
        </div>
    );
}

function ProposalRow({ proposal, onOpen }) {
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
                onDelete={proposal.status !== 'accepted' ? async () => {
                    await api.delete(`/api/proposals/${proposal.id}`);
                    reload();
                } : null}
            />
        </div>
    );
}

// Create or edit a proposal in the wide drawer, with the same editor the
// standalone page uses. The client and project are this project's, so the
// editor only needs a one-company list; it locks both for a new proposal.
function ProposalDrawer({ project, proposal, services, onClose }) {
    const companies = [{
        ...project.company,
        projects: [{ id: project.id, name: project.name }],
    }];

    function saved() {
        reload();
        onClose();
    }

    return (
        <Drawer
            size="wide"
            onClose={onClose}
            actions={proposal && <ProposalActions proposal={proposal} onChange={reload} showBadge={false} />}
        >
            {proposal && (
                <DrawerByline>
                    <DrawerDate label="Created" date={proposal.created_at} />
                    <ProposalStatusBadge proposal={proposal} />
                </DrawerByline>
            )}
            <h2 className="drawer__title">{proposal ? proposal.title : 'New proposal'}</h2>
            <ProposalEditor
                key={proposal?.id ?? 'new'}
                proposal={proposal && { ...proposal, project: { id: project.id, name: project.name } }}
                companies={companies}
                services={services}
                presetCompanyId={project.company_id}
                presetProjectId={project.id}
                onSaved={saved}
                onCancel={onClose}
            />
        </Drawer>
    );
}

function ProposalsTab({ project, services }) {
    const [creating, setCreating] = useState(false);
    const [selectedProposalId, setSelectedProposalId] = useState(null);
    const selectedProposal = project.proposals.find((p) => p.id === selectedProposalId) || null;

    return (
        <div>
            <TabToolbar addLabel="New proposal" onAdd={() => setCreating(true)} />
            <div className="card card--flush">
                {project.proposals.length === 0 ? (
                    <EmptyState text="No proposals for this project yet." />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="project-proposals__title">Title</div>
                            <div className="project-proposals__estimate">Estimate</div>
                            <div className="project-proposals__status">Status</div>
                            <div />
                        </div>
                        {project.proposals.map((proposal) => (
                            <ProposalRow key={proposal.id} proposal={proposal} onOpen={setSelectedProposalId} />
                        ))}
                    </>
                )}
            </div>

            {selectedProposal && (
                <ProposalDrawer
                    project={project}
                    proposal={selectedProposal}
                    services={services}
                    onClose={() => setSelectedProposalId(null)}
                />
            )}

            {creating && (
                <ProposalDrawer
                    project={project}
                    proposal={null}
                    services={services}
                    onClose={() => setCreating(false)}
                />
            )}
        </div>
    );
}

// Copies a proposal's line items as invoice items. When less than the full
// proposal amount remains in the project's budget (some of it already
// invoiced), scales each item down proportionally so the new invoice starts
// at exactly what's left, rather than re-billing the full proposal total.
function proposalToInvoiceItems(proposal, remaining) {
    const items = proposal.items.map((item) => ({
        description: item.description,
        details: item.details && item.details !== item.description ? item.details : '',
        amount: parseFloat(item.quantity) * parseFloat(item.rate),
        service_id: item.service_id ? String(item.service_id) : '',
    }));
    const proposalTotal = items.reduce((s, i) => s + i.amount, 0);
    const scale = proposalTotal > 0 && remaining < proposalTotal ? Math.max(remaining, 0) / proposalTotal : 1;
    return items.map((item) => ({ ...item, amount: (item.amount * scale).toFixed(2) }));
}

function emptyInvoiceForm(defaultTerms) {
    const issuedOn = todayLocal();
    const terms = defaultTerms || 'net_30';
    return {
        proposal_id: '', items: [{ description: '', amount: '' }], surcharge: true,
        issued_on: issuedOn, payment_terms: terms, due_on: calculateDueDate(issuedOn, terms),
    };
}

// Create mode for an invoice, in the wide drawer: optionally seeded from a
// proposal's line items, then dates, items, totals and the card-fee toggle.
function NewInvoiceDrawer({ project, remaining, onClose }) {
    const defaultTerms = project.company.effective_payment_terms;
    const proposalsWithItems = project.proposals.filter((p) => p.items.length > 0);

    // Start from the accepted proposal's line items when there's exactly
    // one to choose from -- otherwise let the user pick.
    const [form, setForm] = useState(() => {
        const accepted = proposalsWithItems.filter((p) => p.status === 'accepted');
        return accepted.length === 1
            ? { ...emptyInvoiceForm(defaultTerms), proposal_id: String(accepted[0].id), items: proposalToInvoiceItems(accepted[0], remaining) }
            : emptyInvoiceForm(defaultTerms);
    });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const selectedProposal = proposalsWithItems.find((p) => String(p.id) === form.proposal_id);
    const selectedProposalTotal = selectedProposal
        ? selectedProposal.items.reduce((s, i) => s + parseFloat(i.quantity) * parseFloat(i.rate), 0)
        : 0;
    const wasScaledToRemaining = selectedProposal && remaining < selectedProposalTotal;
    const formSubtotal = invoiceSubtotal(form.items);
    const formTotal = invoiceTotal(form.items, form.surcharge);

    function copyFromProposal(proposalId) {
        if (!proposalId) {
            setForm({ ...form, proposal_id: '', items: [{ description: '', amount: '' }] });
            return;
        }
        const proposal = proposalsWithItems.find((p) => String(p.id) === proposalId);
        setForm({ ...form, proposal_id: proposalId, items: proposalToInvoiceItems(proposal, remaining) });
    }

    function updateItem(idx, field, value) {
        const items = form.items.map((it, i) => (i === idx ? { ...it, [field]: value } : it));
        setForm({ ...form, items });
    }
    function addItemRow() {
        setForm({ ...form, items: [...form.items, { description: '', amount: '' }] });
    }
    function removeItemRow(idx) {
        setForm({ ...form, items: form.items.filter((_, i) => i !== idx) });
    }

    async function createInvoice(e) {
        e.preventDefault();
        const validItems = form.items.filter((i) => i.description.trim() && parseFloat(i.amount) > 0);
        if (validItems.length === 0) {
            setError(
                form.proposal_id && remaining <= 0
                    ? "This project's budget is already fully invoiced, so the copied line items scaled to $0.00. Increase the budget or enter amounts manually below."
                    : 'Add at least one line item with a description and amount.'
            );
            return;
        }
        setSaving(true);
        setError('');
        try {
            await api.post(`/api/companies/${project.company_id}/invoices`, {
                project_id: project.id,
                surcharge: form.surcharge,
                issued_on: form.issued_on,
                payment_terms: form.payment_terms,
                due_on: form.due_on,
                items: validItems,
            });
            reload();
            onClose();
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer size="wide" onClose={onClose}>
            <h2 className="drawer__title">New invoice</h2>
            <form onSubmit={createInvoice} className="invoice-form">
                {proposalsWithItems.length > 0 && (
                    <div className="invoice-form__section">
                        <select
                            value={form.proposal_id}
                            onChange={(e) => copyFromProposal(e.target.value)}
                            className="input"
                        >
                            <option value="">Copy line items from a proposal…</option>
                            {proposalsWithItems.map((p) => (
                                <option key={p.id} value={p.id}>{p.title} ({formatCurrency(p.estimate_amount)})</option>
                            ))}
                        </select>
                        {wasScaledToRemaining && (
                            remaining <= 0 ? (
                                <div className="form-error">
                                    This project's budget is already fully invoiced, so these line items scaled to $0.00 — increase the budget or edit the amounts below.
                                </div>
                            ) : (
                                <div className="form-hint form-hint--attached">
                                    Scaled to the {formatCurrency(remaining)} left in the budget.
                                </div>
                            )
                        )}
                    </div>
                )}

                <div className="invoice-form__section">
                    <InvoiceDateFields values={form} onChange={(patch) => setForm((current) => ({ ...current, ...patch }))} />
                </div>

                <div className="invoice-form__items">
                    {form.items.map((item, idx) => (
                        <div key={idx} className="invoice-form__item">
                            <div className="invoice-form__item-row">
                                <input
                                    placeholder="Line item description (required)"
                                    value={item.description}
                                    onChange={(e) => updateItem(idx, 'description', e.target.value)}
                                    className="input invoice-form__description"
                                />
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="Amount"
                                    value={item.amount}
                                    onChange={(e) => updateItem(idx, 'amount', e.target.value)}
                                    className="input invoice-form__amount"
                                />
                                {form.items.length > 1 && (
                                    <Button type="button" variant="link-accent" onClick={() => removeItemRow(idx)}>Remove</Button>
                                )}
                            </div>
                            <textarea
                                placeholder="Additional notes shown to the client (optional, not required)"
                                value={item.details || ''}
                                onChange={(e) => updateItem(idx, 'details', e.target.value)}
                                rows={2}
                                className="input invoice-form__details"
                            />
                        </div>
                    ))}
                    <Button type="button" variant="link-accent" onClick={addItemRow}>+ Add line item</Button>
                </div>

                <div className="invoice-form__section totals">
                    <div className="totals__row totals__row--muted">
                        <span>Subtotal</span>
                        <span className="totals__value">{formatCurrency(formSubtotal)}</span>
                    </div>
                    <div className="totals__row totals__row--strong">
                        <span>Total</span>
                        <span className="totals__value">{formatCurrency(formTotal)}</span>
                    </div>
                </div>

                <div className="invoice-form__section">
                    <Toggle
                        checked={form.surcharge}
                        onChange={(value) => setForm({ ...form, surcharge: value })}
                        label="Offer to pay by card (adds a 3% fee, shown only at checkout)"
                    />
                </div>
                {error && <div className="form-message form-message--error form-message--spaced">{error}</div>}
                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>Create draft invoice</Button>
                </div>
            </form>
        </Drawer>
    );
}

// An invoice in the wide drawer: the shared InvoiceDetail (as on the
// standalone page) in the standard drawer frame -- byline, title, and the
// invoice actions beside the close button, plus delete. `detail` is the
// /api/invoices/{id} payload; `onRefresh` reloads it after a change.
function InvoiceDrawer({ detail, onRefresh, onClose }) {
    async function deleteInvoice(invoice) {
        const amount = formatCurrency(invoiceTotal(invoice.items, invoice.surcharge));
        if (!confirm(`Delete this ${amount} invoice? This can't be undone.`)) return;
        try {
            await api.delete(`/api/invoices/${invoice.id}`);
            onClose();
            reload();
        } catch (err) {
            alert(err.message || 'Could not delete this invoice.');
        }
    }

    return (
        <InvoiceDetail
            invoice={detail.invoice}
            studio={detail.studio}
            invoicingDefaults={detail.invoicingDefaults}
            onChange={onRefresh}
            bare
            renderFrame={({ invoice, actions, children }) => (
                <Drawer
                    size="wide"
                    onClose={onClose}
                    actions={
                        <>
                            {actions}
                            {invoice.status !== 'paid' && (
                                <button onClick={() => deleteInvoice(invoice)} title="Delete invoice" className="icon-btn icon-btn--danger drawer__action">
                                    <Trash />
                                </button>
                            )}
                        </>
                    }
                >
                    <DrawerByline>
                        <DrawerDate label="Issued" date={invoice.issued_on} />
                        <InvoiceStatusBadge invoice={invoice} />
                    </DrawerByline>
                    <h2 className="drawer__title">Invoice #{invoice.invoice_number}</h2>
                    <p className="drawer__meta drawer__section">
                        <InvoiceDueLine invoice={invoice} />
                    </p>
                    {children}
                </Drawer>
            )}
        />
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
            <div className="project-billing__total">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</div>
            <div className="project-billing__status"><InvoiceStatusBadge invoice={invoice} /></div>
            <RowActions
                openLabel="Open invoice"
                deleteLabel="Delete invoice"
                confirmMessage={`Delete invoice #${invoice.invoice_number} (${formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))})? Its time and expenses go back to unbilled. This can't be undone.`}
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
    const [detail, setDetail] = useState(null); // the open invoice's /api/invoices/{id} payload
    const [loadingId, setLoadingId] = useState(null);
    const budget = parseFloat(project.budget) || 0;
    const totalInvoiced = project.invoices.reduce((s, inv) => s + invoiceTotal(inv.items, inv.surcharge), 0);
    const remaining = budget - totalInvoiced;

    // The drawer opens once its invoice has loaded, rather than opening
    // empty and filling in -- swapping the content in would replay the
    // drawer's entrance.
    async function openInvoice(id) {
        if (loadingId) return;
        setLoadingId(id);
        try {
            setDetail(await api.get(`/api/invoices/${id}`));
        } finally {
            setLoadingId(null);
        }
    }

    async function refreshInvoice() {
        setDetail(await api.get(`/api/invoices/${detail.invoice.id}`));
        reload();
    }

    return (
        <div>
            <TabToolbar
                summary={budget > 0 && (
                    <div className="project-billing__budget">
                        Budget <span className="project-billing__figure">{formatCurrency(budget)}</span>
                        {' · '}
                        Remaining <span className={`project-billing__figure${remaining < 0 ? ' project-billing__figure--negative' : ''}`}>{formatCurrency(remaining)}</span>
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

            {detail && (
                <InvoiceDrawer
                    key={detail.invoice.id}
                    detail={detail}
                    onRefresh={refreshInvoice}
                    onClose={() => setDetail(null)}
                />
            )}

            {creating && <NewInvoiceDrawer project={project} remaining={remaining} onClose={() => setCreating(false)} />}
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
                <label className="choice">
                    <input
                        type="checkbox"
                        checked={values.is_billable}
                        disabled={disabled}
                        onChange={(e) => onCommit('is_billable', e.target.checked)}
                    />
                    Billable to this project
                </label>
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

function AssignedStaff({ project, canManageTeam, assignableStaff }) {
    const [assigned, setAssigned] = useState(project.active_users || []);
    const [pickId, setPickId] = useState('');
    const [busy, setBusy] = useState(false);

    const available = (assignableStaff || []).filter((tm) => !assigned.some((a) => a.id === tm.id));

    async function assign(e) {
        e.preventDefault();
        if (!pickId) return;
        setBusy(true);
        try {
            const updated = await api.post(`/api/projects/${project.id}/assignments`, { user_id: pickId });
            setAssigned(updated);
            setPickId('');
        } finally {
            setBusy(false);
        }
    }

    async function unassign(user) {
        if (!confirm(`Remove ${user.name} from this project? They'll keep read-only access to their past work here.`)) return;
        setBusy(true);
        try {
            await api.delete(`/api/projects/${project.id}/assignments/${user.id}`);
            setAssigned((current) => current.filter((u) => u.id !== user.id));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="page-section">
            <h2 className="section-heading">Assigned staff</h2>
            {canManageTeam && (
                <form onSubmit={assign} className="inline-form page-section--tight">
                    <select
                        value={pickId}
                        onChange={(e) => setPickId(e.target.value)}
                        className="input inline-form__grow"
                    >
                        <option value="">Assign staff&hellip;</option>
                        {available.map((staffer) => (
                            <option key={staffer.id} value={staffer.id}>{staffer.name}</option>
                        ))}
                    </select>
                    <Button type="submit" variant="confirm" disabled={busy || !pickId}>Assign</Button>
                </form>
            )}
            {assigned.length === 0 ? (
                <EmptyState text="No staff assigned to this project yet." />
            ) : (
                <div className="cluster">
                    {assigned.map((user) => (
                        <span key={user.id} className="person-chip">
                            {user.name}
                            {canManageTeam && (
                                <button onClick={() => unassign(user)} className="icon-btn icon-btn--danger">
                                    <X />
                                </button>
                            )}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

function TeamTab({ project, canManageTeam, assignableStaff }) {
    const [names, setNames] = useState(project.team_names || []);
    const [input, setInput] = useState('');
    const [saving, setSaving] = useState(false);

    async function persist(next) {
        setSaving(true);
        try {
            await api.patch(`/api/projects/${project.id}`, { team_names: next });
            setNames(next);
        } finally {
            setSaving(false);
        }
    }

    function add(e) {
        e.preventDefault();
        if (!input.trim() || names.includes(input.trim())) return;
        const next = [...names, input.trim()];
        setInput('');
        persist(next);
    }

    function remove(name) {
        persist(names.filter((n) => n !== name));
    }

    return (
        <div>
            <AssignedStaff project={project} canManageTeam={canManageTeam} assignableStaff={assignableStaff} />

            <h2 className="section-heading">Other names (not linked to a login)</h2>
            <form onSubmit={add} className="inline-form page-section--tight">
                <input
                    placeholder="Add team member name"
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    className="input inline-form__grow"
                />
                <Button type="submit" variant="confirm" disabled={saving}>Add</Button>
            </form>
            {names.length === 0 ? (
                <EmptyState text="No one assigned yet." />
            ) : (
                <div className="cluster">
                    {names.map((name) => (
                        <span key={name} className="person-chip">
                            {name}
                            <button onClick={() => remove(name)} className="icon-btn icon-btn--danger">
                                <X />
                            </button>
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function ProjectsShow({ project, canManageTeam, assignableStaff, services }) {
    const tabs = canManageTeam ? ALL_TABS : ALL_TABS.filter((t) => !MANAGER_ONLY_TABS.includes(t));
    const [tab, setTab] = useState('Overview');

    return (
        <AppLayout>
            <Head title={project.name} />
            <PageHeader
                back={{ href: '/projects', label: 'Projects' }}
                title={project.name}
                actions={<ProjectStatusBadge project={project} />}
                subtitle={<Link href={`/clients/${project.company.id}`} className="link">{project.company.name}</Link>}
            />

            <TabBar tab={tab} setTab={setTab} tabs={tabs} />

            {tab === 'Overview' && <OverviewTab project={project} />}
            {tab === 'Tasks' && <TasksTab project={project} />}
            {tab === 'Notes' && <NotesTab project={project} />}
            {tab === 'Messages' && <MessagesTab project={project} />}
            {tab === 'Time' && <TimeTab project={project} />}
            {tab === 'Proposals' && <ProposalsTab project={project} services={services} />}
            {tab === 'Billing' && <BillingTab project={project} />}
            {tab === 'Expenses' && <ExpensesTab project={project} />}
            {tab === 'Team' && <TeamTab project={project} canManageTeam={canManageTeam} assignableStaff={assignableStaff} />}
        </AppLayout>
    );
}

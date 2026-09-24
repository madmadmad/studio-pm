import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CaretRight, Check, CheckCircle, Copy, DotsSixVertical, DownloadSimple, Eye, Paperclip, PaperPlaneTilt, PencilSimple, Trash, X } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import RichTextEditor from '../../Components/RichTextEditor';
import Toggle from '../../Components/Toggle';
import MessagesPanel from '../../Components/MessagesPanel';
import InvoiceDateFields from '../../Components/InvoiceDateFields';
import { ProjectStatusBadge, TaskStatusBadge, InvoiceStatusBadge, ProposalStatusBadge, ExpenseStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, formatFileSize, invoiceSubtotal, invoiceTotal } from '../../lib/format';
import { calculateDueDate, todayLocal } from '../../lib/paymentTerms';
import { api } from '../../lib/api';
import { copyToClipboard } from '../../lib/clipboard';
import PageHeader from '../../Components/PageHeader';
import Drawer from '../../Components/Drawer';
import TabBar from '../../Components/TabBar';

const ALL_TABS = ['Overview', 'Tasks', 'Notes', 'Messages', 'Time', 'Proposals', 'Billing', 'Expenses', 'Team'];
const MANAGER_ONLY_TABS = ['Proposals', 'Billing', 'Expenses'];

function reload() {
    router.reload({ only: ['project'] });
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
            <div className={`project-overview__stats${budget > 0 ? ' project-overview__stats--wide' : ''}`}>
                <div className="card card--padded metric-card">
                    <div className="metric-card__label">Tasks</div>
                    <div className="metric-card__value">{doneTasks}/{project.tasks.length}</div>
                </div>
                <div className="card card--padded metric-card">
                    <div className="metric-card__label">Hours logged</div>
                    <div className="metric-card__value">{totalHours}h</div>
                </div>
                <div className="card card--padded metric-card">
                    <div className="metric-card__label">Unbilled hours</div>
                    <div className="metric-card__value">{unbilledHours}h</div>
                </div>
                <div className="card card--padded metric-card">
                    <div className="metric-card__label">Total invoiced</div>
                    <div className="metric-card__value">{formatCurrency(totalInvoiced)}</div>
                </div>
                {budget > 0 && (
                    <>
                        <div className="card card--padded metric-card">
                            <div className="metric-card__label">Budget</div>
                            <div className="metric-card__value">{formatCurrency(budget)}</div>
                        </div>
                        <div className="card card--padded metric-card">
                            <div className="metric-card__label">Remaining</div>
                            <div className={`metric-card__value${remaining < 0 ? ' metric-card__value--negative' : ''}`}>{formatCurrency(remaining)}</div>
                        </div>
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

    return (
        <div className="grid-row">
            <div className="task-list__title">
                <span className="u-truncate">{task.title}</span>
                <button
                    onClick={() => onOpen(task.id)}
                    title="Open task"
                    className="row-action"
                >
                    <CaretRight size={14} weight="bold" />
                </button>
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
            <div className="task-list__due">
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

function SubtasksSection({ task, onChange }) {
    const [title, setTitle] = useState('');
    const [dragIndex, setDragIndex] = useState(null);
    const subtasks = task.subtasks || [];

    async function addSubtask(e) {
        e.preventDefault();
        if (!title.trim()) return;
        await api.post(`/api/tasks/${task.id}/subtasks`, { title });
        setTitle('');
        onChange();
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
            <div className="section-label">Subtasks</div>
            {subtasks.length > 0 && (
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
                </div>
            )}
            <form onSubmit={addSubtask} className="inline-form subtask-list__add">
                <input
                    placeholder="Add subtask"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    className="input inline-form__grow"
                />
                <Button type="submit" variant="confirm" className="btn--lg subtask-list__add-button">
                    Add
                </Button>
            </form>
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
            <div className="task-files__header">
                <div className="section-label section-label--flush">Files</div>
                <Button variant="link" onClick={() => inputRef.current.click()} disabled={uploading}>
                    <Paperclip size={14} /> {uploading ? 'Uploading…' : 'Add file'}
                </Button>
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

function TaskDrawer({ task, teamNames, onClose, onChange }) {
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');

    useEffect(() => {
        if (task) {
            setTitle(task.title);
            setDescription(task.description ?? '');
        }
    }, [task?.id]);

    async function updateField(field, value) {
        await api.patch(`/api/tasks/${task.id}`, { [field]: value || null });
        onChange();
    }

    return (
        <Drawer
            onClose={onClose}
            header={
                <select
                    value={task.status}
                    onChange={(e) => updateField('status', e.target.value)}
                    className="input input--xs input--inline task-drawer__status"
                >
                    {TASK_STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                </select>
            }
        >
            <input
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onBlur={() => title !== task.title && updateField('title', title)}
                className="inline-edit inline-edit--title"
            />

            <div className="drawer__section drawer__section--divided task-drawer__assignee">
                <div className="section-label section-label--tight">Assignee</div>
                <select
                    value={task.assignee ?? ''}
                    onChange={(e) => updateField('assignee', e.target.value)}
                    className="input input--xs input--inline"
                >
                    <option value="">Unassigned</option>
                    {teamNames.map((name) => <option key={name} value={name}>{name}</option>)}
                </select>
            </div>

            <div className="drawer__section">
                <div className="section-label">Description</div>
                <textarea
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    onBlur={() => description !== (task.description ?? '') && updateField('description', description)}
                    rows={6}
                    placeholder="Add a description…"
                    className="input"
                />
            </div>

            <SubtasksSection task={task} onChange={onChange} />

            <FilesSection task={task} onChange={onChange} />

            <div className="drawer__section">
                <div className="section-label">Due date</div>
                <input
                    type="date"
                    value={task.due_date ? task.due_date.slice(0, 10) : ''}
                    onChange={(e) => updateField('due_date', e.target.value)}
                    className="input"
                />
            </div>

            <div className="drawer__meta">Created {formatDate(task.created_at)}</div>
        </Drawer>
    );
}

function TasksTab({ project }) {
    const [title, setTitle] = useState('');
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

    async function addTask(e) {
        e.preventDefault();
        if (!title) return;
        await api.post(`/api/projects/${project.id}/tasks`, { title });
        setTitle('');
        reload();
    }

    return (
        <div>
            <form onSubmit={addTask} className="inline-form page-section--tight">
                <input
                    placeholder="New task"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    className="input inline-form__grow"
                />
                <Button type="submit" variant="confirm">Add</Button>
            </form>
            <div className="card card--flush">
                {project.tasks.length === 0 ? (
                    <EmptyState text="No tasks yet." />
                ) : (
                    <>
                        <div className="grid-row grid-row--head">
                            <div className="task-list__title">Task</div>
                            <div className="task-list__assignee">Assignee</div>
                            <div className="task-list__due">Due date</div>
                            <div className="task-list__status">Status</div>
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
                    onClose={() => setSelectedTaskId(null)}
                    onChange={reload}
                />
            )}
        </div>
    );
}

function NoteRow({ note, onOpen }) {
    return (
        <button
            onClick={() => onOpen(note.id)}
            className="list-row note-list__row"
        >
            <span className="note-list__title">{note.title || 'Untitled note'}</span>
            <span className="note-list__meta">
                <span className="note-list__date">{formatDate(note.updated_at)}</span>
                <span className="row-action">
                    <CaretRight size={14} weight="bold" />
                </span>
            </span>
        </button>
    );
}

function NoteDrawer({ note, onClose, onChange }) {
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const bodySaveTimeout = useRef(null);
    const pendingBody = useRef(null); // { id, value } awaiting the debounced save
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    useEffect(() => {
        if (note) {
            setTitle(note.title ?? '');
            setBody(note.body ?? '');
        }
    }, [note?.id]);

    // Saves the pending body now, if there is one. Reads only refs, so it's
    // safe to call from the unmount cleanup below. The id is captured with
    // the value, so a save always lands on the note that was being edited.
    async function savePendingBody() {
        const pending = pendingBody.current;
        if (!pending) return;
        pendingBody.current = null;
        await api.patch(`/api/notes/${pending.id}`, { body: pending.value || null });
        onChangeRef.current();
    }

    // Flush any pending debounced body save if the drawer closes mid-type,
    // so the last few keystrokes aren't silently dropped.
    useEffect(
        () => () => {
            clearTimeout(bodySaveTimeout.current);
            savePendingBody();
        },
        []
    );

    async function updateField(field, value) {
        await api.patch(`/api/notes/${note.id}`, { [field]: value || null });
        onChange();
    }

    function handleBodyChange(value) {
        setBody(value);
        clearTimeout(bodySaveTimeout.current);
        pendingBody.current = { id: note.id, value };
        // Local state already renders the change instantly -- debounce the
        // network save (and the resulting project reload) so typing
        // doesn't fire a request, and a resync, on every keystroke.
        bodySaveTimeout.current = setTimeout(savePendingBody, 600);
    }

    async function remove() {
        // Drop any pending body save -- the note is about to be deleted.
        clearTimeout(bodySaveTimeout.current);
        pendingBody.current = null;
        await api.delete(`/api/notes/${note.id}`);
        onClose();
        onChange();
    }

    return (
        <Drawer
            onClose={onClose}
            header={<span className="drawer__meta">Edited {formatDate(note.updated_at)}</span>}
            actions={
                <button onClick={remove} title="Delete note" className="icon-btn icon-btn--danger drawer__action">
                    <Trash />
                </button>
            }
        >
            <input
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onBlur={() => title !== (note.title ?? '') && updateField('title', title)}
                placeholder="Untitled note"
                className="inline-edit inline-edit--title"
            />

            <RichTextEditor value={body} onChange={handleBodyChange} />
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
            <div className="toolbar page-section--tight">
                <Button variant="confirm" onClick={createNote} disabled={creating}>
                    + New note
                </Button>
            </div>
            <div className="card card--flush">
                {project.notes.length === 0 ? (
                    <EmptyState text="No notes yet." />
                ) : (
                    project.notes.map((note) => (
                        <NoteRow key={note.id} note={note} onOpen={setSelectedNoteId} />
                    ))
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

function MessagesTab({ project }) {
    const { props } = usePage();
    const currentUser = props.auth?.user;

    const recipientOptions = [
        ...(project.active_users || [])
            .filter((u) => u.id !== currentUser?.id)
            .map((u) => ({ token: `user:${u.id}`, name: u.name, sublabel: 'Team' })),
        ...(project.company.contacts || [])
            .filter((c) => c.has_portal_access)
            .map((c) => ({ token: `contact:${c.id}`, name: c.name, sublabel: 'Client' })),
    ];

    return (
        <MessagesPanel
            project={project}
            currentActorType="user"
            currentActorId={currentUser?.id}
            currentActorRole={currentUser?.role}
            recipientOptions={recipientOptions}
            endpoints={{
                create: `/api/projects/${project.id}/messages`,
                reply: (id) => `/api/messages/${id}/replies`,
                join: (id) => `/api/messages/${id}/join`,
                update: (id) => `/api/messages/${id}`,
                destroy: (id) => `/api/messages/${id}`,
                attachmentUrl: (id) => `/api/attachments/${id}`,
                attachmentThumbnailUrl: (id) => `/api/attachments/${id}/thumbnail`,
            }}
            onChange={reload}
        />
    );
}

function TimeEntryRow({ entry, onOpen }) {
    return (
        <div className="grid-row">
            <div className="time-list__date">{formatDate(entry.date)}</div>
            <div className="time-list__hours">{entry.hours}h</div>
            <div className="time-list__detail">
                <span className="time-list__text">{entry.task ? entry.task.title : entry.note || '—'}</span>
                <button
                    onClick={() => onOpen(entry.id)}
                    title="Open entry"
                    className="row-action"
                >
                    <CaretRight size={14} weight="bold" />
                </button>
            </div>
            <div className="time-list__status">
                {entry.billed ? <Badge tone="fern" label="Billed" /> : <Badge tone="neutral" label="Unbilled" />}
            </div>
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
            header={entry.billed ? <Badge tone="fern" label="Billed" /> : <Badge tone="neutral" label="Unbilled" />}
            actions={
                <button onClick={remove} title="Delete entry" className="icon-btn icon-btn--danger drawer__action">
                    <Trash />
                </button>
            }
        >
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

function TimeTab({ project }) {
    const [form, setForm] = useState({ date: '', task_id: '', hours: '', note: '' });
    const [saving, setSaving] = useState(false);
    const [selectedEntryId, setSelectedEntryId] = useState(null);
    const selectedEntry = project.time_entries.find((e) => e.id === selectedEntryId) || null;

    async function logTime(e) {
        e.preventDefault();
        if (!form.date || !form.hours) return;
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
            setForm({ date: '', task_id: '', hours: '', note: '' });
            reload();
        } finally {
            setSaving(false);
        }
    }

    return (
        <div>
            <form onSubmit={logTime} className="card card--padded form-grid form-grid--4 form-grid--tight page-section--tight">
                <input required type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} className="input" />
                <select value={form.task_id} onChange={(e) => setForm({ ...form, task_id: e.target.value })} className="input">
                    <option value="">No task</option>
                    {project.tasks.map((t) => <option key={t.id} value={t.id}>{t.title}</option>)}
                </select>
                <input required type="number" min="0.25" step="0.25" placeholder="Hours" value={form.hours} onChange={(e) => setForm({ ...form, hours: e.target.value })} className="input" />
                <input placeholder="Note" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} className="input" />
                <Button type="submit" variant="confirm" disabled={saving} className="form-grid__submit">Log time</Button>
            </form>
            <div className="card card--flush">
                {project.time_entries.length === 0 ? (
                    <EmptyState text="No time logged yet." />
                ) : (
                    <>
                        <div className="grid-row grid-row--head">
                            <div className="time-list__date">Date</div>
                            <div className="time-list__hours">Hours</div>
                            <div className="time-list__detail">Task / Note</div>
                            <div className="time-list__status">Status</div>
                        </div>
                        {project.time_entries.map((entry) => (
                            <TimeEntryRow key={entry.id} entry={entry} onOpen={setSelectedEntryId} />
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
        </div>
    );
}

function ProposalsTab({ project }) {
    return (
        <div>
            <div className="toolbar page-section--tight">
                <Link
                    href={`/proposals/create?company_id=${project.company_id}&project_id=${project.id}`}
                    className="btn btn--primary"
                >
                    New proposal
                </Link>
            </div>

            <div className="card card--flush">
                {project.proposals.length === 0 ? (
                    <EmptyState text="No proposals for this project yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Estimate</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {project.proposals.map((proposal) => (
                                <tr key={proposal.id}>
                                    <td>
                                        <Link href={`/proposals/${proposal.id}/edit`} className="link">
                                            {proposal.title}
                                        </Link>
                                    </td>
                                    <td className="table__cell--numeric">
                                        {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                                    </td>
                                    <td><ProposalStatusBadge proposal={proposal} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
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

function BillingTab({ project }) {
    const defaultTerms = project.company.effective_payment_terms;
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(() => emptyInvoiceForm(defaultTerms));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [copiedInvoiceId, setCopiedInvoiceId] = useState(null);
    const [deletingInvoiceId, setDeletingInvoiceId] = useState(null);
    const budget = parseFloat(project.budget) || 0;
    const totalInvoiced = project.invoices.reduce((s, inv) => s + invoiceTotal(inv.items, inv.surcharge), 0);
    const remaining = budget - totalInvoiced;
    const proposalsWithItems = project.proposals.filter((p) => p.items.length > 0);
    const selectedProposal = proposalsWithItems.find((p) => String(p.id) === form.proposal_id);
    const selectedProposalTotal = selectedProposal
        ? selectedProposal.items.reduce((s, i) => s + parseFloat(i.quantity) * parseFloat(i.rate), 0)
        : 0;
    const wasScaledToRemaining = selectedProposal && remaining < selectedProposalTotal;
    const formSubtotal = invoiceSubtotal(form.items);
    const formTotal = invoiceTotal(form.items, form.surcharge);

    function openForm() {
        // Start from the most recently accepted proposal's line items when
        // there's exactly one to choose from -- otherwise let the user pick.
        const accepted = proposalsWithItems.filter((p) => p.status === 'accepted');
        if (accepted.length === 1) {
            setForm({ ...emptyInvoiceForm(defaultTerms), proposal_id: String(accepted[0].id), items: proposalToInvoiceItems(accepted[0], remaining) });
        } else {
            setForm(emptyInvoiceForm(defaultTerms));
        }
        setError('');
        setShowForm(true);
    }

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
            setForm(emptyInvoiceForm(defaultTerms));
            setShowForm(false);
            reload();
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    async function sendInvoice(invoice) {
        await api.post(`/api/invoices/${invoice.id}/send`);
        reload();
    }

    // Quick action for the common case (a check came in) -- anything else
    // (e.g. "other") is recorded from the invoice detail page instead.
    async function markInvoicePaid(invoice) {
        await api.post(`/api/invoices/${invoice.id}/mark-paid`, { method: 'check' });
        reload();
    }

    async function copyInvoiceLink(invoice) {
        const ok = await copyToClipboard(`${window.location.origin}/i/${invoice.public_token}`);
        if (!ok) {
            alert('Could not copy the link. Copy it manually instead.');
            return;
        }
        setCopiedInvoiceId(invoice.id);
        setTimeout(() => setCopiedInvoiceId((id) => (id === invoice.id ? null : id)), 1500);
    }

    async function deleteInvoice(invoice) {
        if (deletingInvoiceId === invoice.id) return; // already in flight -- ignore a repeat click
        const amount = formatCurrency(invoiceTotal(invoice.items, invoice.surcharge));
        if (!confirm(`Delete this ${amount} invoice? This can't be undone.`)) return;
        setDeletingInvoiceId(invoice.id);
        try {
            await api.delete(`/api/invoices/${invoice.id}`);
            reload();
        } catch (err) {
            alert(err.message || 'Could not delete this invoice.');
        } finally {
            setDeletingInvoiceId(null);
        }
    }

    return (
        <div>
            <div className="toolbar toolbar--split page-section--tight">
                {budget > 0 ? (
                    <div className="project-billing__budget">
                        Budget <span className="project-billing__figure">{formatCurrency(budget)}</span>
                        {' · '}
                        Remaining <span className={`project-billing__figure${remaining < 0 ? ' project-billing__figure--negative' : ''}`}>{formatCurrency(remaining)}</span>
                    </div>
                ) : <div />}
                <Button onClick={() => (showForm ? setShowForm(false) : openForm())}>
                    {showForm ? 'Cancel' : 'New invoice'}
                </Button>
            </div>

            {showForm && (
                <form onSubmit={createInvoice} className="card card--padded page-section--tight invoice-form">
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
            )}

            <div className="card card--flush">
                {project.invoices.length === 0 ? (
                    <EmptyState text="No invoices for this project yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Issued</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {project.invoices.map((invoice) => (
                                <tr key={invoice.id}>
                                    <td className="table__cell--numeric table__cell--muted">{invoice.invoice_number}</td>
                                    <td>
                                        <Link href={`/invoices/${invoice.id}`} className="link">
                                            {formatDate(invoice.issued_on)}
                                        </Link>
                                    </td>
                                    <td className="table__cell--numeric">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</td>
                                    <td><InvoiceStatusBadge invoice={invoice} /></td>
                                    <td className="table__cell--end">
                                        <div className="table__actions">
                                            <a href={`/i/${invoice.public_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="icon-btn icon-btn--secondary">
                                                <Eye />
                                            </a>
                                            {invoice.status === 'draft' && (
                                                <Link href={`/invoices/${invoice.id}`} title="Edit" className="icon-btn icon-btn--confirm">
                                                    <PencilSimple />
                                                </Link>
                                            )}
                                            {invoice.status === 'draft' && (
                                                <button onClick={() => sendInvoice(invoice)} title="Send" className="icon-btn icon-btn--accent">
                                                    <PaperPlaneTilt />
                                                </button>
                                            )}
                                            {invoice.status !== 'draft' && (
                                                <button onClick={() => copyInvoiceLink(invoice)} title={copiedInvoiceId === invoice.id ? 'Copied!' : 'Copy link'} className="icon-btn icon-btn--secondary">
                                                    {copiedInvoiceId === invoice.id ? <Check /> : <Copy />}
                                                </button>
                                            )}
                                            {invoice.status !== 'draft' && (
                                                <a href={`/invoices/${invoice.id}/pdf`} title="Download PDF" className="icon-btn icon-btn--secondary">
                                                    <DownloadSimple />
                                                </a>
                                            )}
                                            {invoice.status === 'sent' && (
                                                <button onClick={() => markInvoicePaid(invoice)} title="Mark paid (check)" className="icon-btn icon-btn--confirm">
                                                    <CheckCircle />
                                                </button>
                                            )}
                                            {invoice.status !== 'paid' && (
                                                <button
                                                    onClick={() => deleteInvoice(invoice)}
                                                    disabled={deletingInvoiceId === invoice.id}
                                                    title="Delete"
                                                    className="icon-btn icon-btn--danger"
                                                >
                                                    <Trash />
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </div>
    );
}

function ExpensesTab({ project }) {
    const expenses = project.expenses || [];
    const [form, setForm] = useState({ name: '', amount: '', category_id: '', is_billable: false, markup_percent: '0', date: new Date().toISOString().slice(0, 10) });
    const [saving, setSaving] = useState(false);

    async function addExpense(e) {
        e.preventDefault();
        if (!form.name.trim() || !(parseFloat(form.amount) > 0) || !form.date) return;
        setSaving(true);
        try {
            await api.post('/api/expenses', { ...form, project_id: project.id });
            setForm({ name: '', amount: '', category_id: '', is_billable: false, markup_percent: '0', date: new Date().toISOString().slice(0, 10) });
            reload();
        } finally {
            setSaving(false);
        }
    }

    async function remove(expense) {
        if (!confirm(`Delete "${expense.name}"?`)) return;
        await api.delete(`/api/expenses/${expense.id}`);
        reload();
    }

    const total = expenses.reduce((s, e) => s + parseFloat(e.amount), 0);

    return (
        <div>
            <form onSubmit={addExpense} className="card card--padded form-grid form-grid--tight page-section--tight">
                <input required placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="input form-grid__full" />
                <input required type="number" min="0.01" step="0.01" placeholder="Amount" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="input u-tabular-nums" />
                <input required type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} className="input" />
                <label className="choice">
                    <input type="checkbox" checked={form.is_billable} onChange={(e) => setForm({ ...form, is_billable: e.target.checked })} />
                    Billable to this project
                </label>
                <input
                    type="number" min="0" step="0.01" placeholder="Markup %"
                    value={form.markup_percent}
                    disabled={!form.is_billable}
                    onChange={(e) => setForm({ ...form, markup_percent: e.target.value })}
                    className="input u-tabular-nums"
                />
                <Button type="submit" variant="confirm" disabled={saving} className="form-grid__submit">Add expense</Button>
            </form>
            <div className="project-expenses__total">Total expenses: <span className="project-expenses__total-value">{formatCurrency(total)}</span></div>
            <div className="card card--flush">
                {expenses.length === 0 ? (
                    <EmptyState text="No expenses logged for this project." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th className="table__cell--end">Amount</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {expenses.map((e) => (
                                <tr key={e.id}>
                                    <td>{formatDate(e.date)}</td>
                                    <td className="table__cell--muted">{e.name}</td>
                                    <td><ExpenseStatusBadge expense={e} /></td>
                                    <td className="table__cell--end table__cell--numeric table__cell--negative">{formatCurrency(e.amount)}</td>
                                    <td className="table__cell--end">
                                        {e.billing_status === 'unbilled' && (
                                            <button onClick={() => remove(e)} className="icon-btn icon-btn--danger">
                                                <Trash />
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
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

export default function ProjectsShow({ project, canManageTeam, assignableStaff }) {
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
            {tab === 'Proposals' && <ProposalsTab project={project} />}
            {tab === 'Billing' && <BillingTab project={project} />}
            {tab === 'Expenses' && <ExpensesTab project={project} />}
            {tab === 'Team' && <TeamTab project={project} canManageTeam={canManageTeam} assignableStaff={assignableStaff} />}
        </AppLayout>
    );
}

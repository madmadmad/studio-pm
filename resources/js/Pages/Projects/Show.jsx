import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, CaretRight, Check, CheckCircle, Copy, DotsSixVertical, DownloadSimple, Eye, Paperclip, PaperPlaneTilt, PencilSimple, Trash, X } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import RichTextEditor from '../../Components/RichTextEditor';
import { ProjectStatusBadge, TaskStatusBadge, InvoiceStatusBadge, ProposalStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';

const TABS = ['Overview', 'Tasks', 'Notes', 'Messages', 'Time', 'Proposals', 'Billing', 'Expenses', 'Team'];
const STATUS_OPTIONS = ['active', 'on_hold', 'completed'];

function reload() {
    router.reload({ only: ['project'] });
}

function TabBar({ tab, setTab }) {
    return (
        <div className="flex gap-1 border-b border-border mb-6 overflow-x-auto">
            {TABS.map((t) => (
                <button
                    key={t}
                    onClick={() => setTab(t)}
                    className={`text-sm font-medium px-3 py-2 border-b-2 -mb-px whitespace-nowrap ${
                        tab === t ? 'border-ink text-ink' : 'border-transparent text-sage'
                    }`}
                >
                    {t}
                </button>
            ))}
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
            {project.description && <p className="text-sm text-sage mb-6">{project.description}</p>}
            <div className={`grid gap-4 mb-6 ${budget > 0 ? 'grid-cols-6' : 'grid-cols-4'}`}>
                <div className="bg-white rounded-lg border border-border p-4">
                    <div className="text-xs text-sage mb-1">Tasks</div>
                    <div className="tabular-nums text-xl">{doneTasks}/{project.tasks.length}</div>
                </div>
                <div className="bg-white rounded-lg border border-border p-4">
                    <div className="text-xs text-sage mb-1">Hours logged</div>
                    <div className="tabular-nums text-xl">{totalHours}h</div>
                </div>
                <div className="bg-white rounded-lg border border-border p-4">
                    <div className="text-xs text-sage mb-1">Unbilled hours</div>
                    <div className="tabular-nums text-xl">{unbilledHours}h</div>
                </div>
                <div className="bg-white rounded-lg border border-border p-4">
                    <div className="text-xs text-sage mb-1">Total invoiced</div>
                    <div className="tabular-nums text-xl">{formatCurrency(totalInvoiced)}</div>
                </div>
                {budget > 0 && (
                    <>
                        <div className="bg-white rounded-lg border border-border p-4">
                            <div className="text-xs text-sage mb-1">Budget</div>
                            <div className="tabular-nums text-xl">{formatCurrency(budget)}</div>
                        </div>
                        <div className="bg-white rounded-lg border border-border p-4">
                            <div className="text-xs text-sage mb-1">Remaining</div>
                            <div className={`tabular-nums text-xl ${remaining < 0 ? 'text-brick' : ''}`}>{formatCurrency(remaining)}</div>
                        </div>
                    </>
                )}
            </div>
            {project.team_names?.length > 0 && (
                <div>
                    <div className="text-xs text-sage mb-2">Team</div>
                    <div className="flex gap-2 flex-wrap">
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
        <div className="grid grid-cols-12 gap-2 items-center px-4 py-2 border-b border-border last:border-b-0 text-sm group">
            <div className="col-span-5 flex items-center gap-2">
                <span className="truncate">{task.title}</span>
                <button
                    onClick={() => onOpen(task.id)}
                    title="Open task"
                    className="flex-shrink-0 w-7 h-7 flex items-center justify-center rounded border border-border text-sage opacity-0 group-hover:opacity-100 hover:text-ink hover:border-ink transition-opacity"
                >
                    <CaretRight size={14} weight="bold" />
                </button>
            </div>
            <div className="col-span-3" onClick={(e) => e.stopPropagation()}>
                <select
                    value={task.assignee ?? ''}
                    onChange={(e) => updateField('assignee', e.target.value)}
                    className="h-8 border border-transparent hover:border-border rounded px-2 text-sm w-full bg-transparent text-sage"
                >
                    <option value="">Unassigned</option>
                    {teamNames.map((name) => <option key={name} value={name}>{name}</option>)}
                </select>
            </div>
            <div className="col-span-2">
                <input
                    type="date"
                    value={task.due_date ? task.due_date.slice(0, 10) : ''}
                    onChange={(e) => updateField('due_date', e.target.value)}
                    className="h-8 border border-transparent hover:border-border rounded px-2 text-xs w-full bg-transparent text-sage"
                />
            </div>
            <div className="col-span-2 text-right">
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
            className={`resize-none overflow-hidden ${className}`}
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
            className={`flex items-start gap-2 py-2 border-b border-border last:border-b-0 group ${isDragging ? 'opacity-40' : ''}`}
        >
            <span className="text-sage cursor-grab opacity-0 group-hover:opacity-100 flex-shrink-0 mt-1">
                <DotsSixVertical size={14} weight="bold" />
            </span>
            <button
                onClick={toggleDone}
                className={`mt-0.5 w-5 h-5 rounded flex items-center justify-center flex-shrink-0 text-xs ${
                    subtask.status === 'done' ? 'bg-pine text-white' : 'border border-border'
                }`}
            >
                {subtask.status === 'done' && <Check size={12} weight="bold" />}
            </button>
            <AutoResizeTextarea
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onBlur={() => title !== subtask.title && updateField('title', title)}
                className={`flex-1 min-w-0 text-sm border-none focus:outline-none bg-transparent leading-normal py-0.5 ${
                    subtask.status === 'done' ? 'line-through text-sage' : ''
                }`}
            />
            <button onClick={remove} className="mt-0.5 text-sage hover:text-brick opacity-0 group-hover:opacity-100 flex-shrink-0 px-1">
                <X size={12} />
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
        <div className="mb-6">
            <div className="text-xs font-semibold text-sage mb-2">Subtasks</div>
            {subtasks.length > 0 && (
                <div className="mb-1">
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
            <form onSubmit={addSubtask} className="flex items-center gap-2 pt-2">
                <input
                    placeholder="Add subtask"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    className="flex-1 border border-border rounded px-3 py-2 text-sm"
                />
                <button type="submit" className="bg-pine text-white text-sm font-medium px-3 py-2 rounded flex-shrink-0">
                    Add
                </button>
            </form>
        </div>
    );
}

function formatFileSize(bytes) {
    if (!bytes) return '0 KB';
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unitIndex = 0;
    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }
    return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
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
        <div className="mb-6">
            <div className="flex items-center justify-between mb-2">
                <div className="text-xs font-semibold text-sage">Files</div>
                <button
                    onClick={() => inputRef.current.click()}
                    disabled={uploading}
                    className="text-sm font-medium text-pine flex items-center gap-1 disabled:opacity-50"
                >
                    <Paperclip size={14} /> {uploading ? 'Uploading…' : 'Add file'}
                </button>
                <input ref={inputRef} type="file" onChange={handleFileChange} className="hidden" />
            </div>
            {files.length === 0 ? (
                <EmptyState text="No files yet." />
            ) : (
                <div className="border border-border rounded-lg overflow-hidden">
                    {files.map((file) => (
                        <div
                            key={file.id}
                            className="flex items-center justify-between px-3 py-2 border-b border-border last:border-b-0 group"
                        >
                            <div className="min-w-0">
                                <div className="text-sm truncate">{file.filename}</div>
                                <div className="text-xs text-sage">{formatFileSize(file.size)}</div>
                            </div>
                            <div className="flex items-center gap-1 flex-shrink-0">
                                <a href={file.url} download={file.filename} title="Download" className="text-sage hover:text-ink p-1.5">
                                    <DownloadSimple size={16} />
                                </a>
                                <button
                                    onClick={() => remove(file)}
                                    title="Remove"
                                    className="text-sage hover:text-brick p-1.5 opacity-0 group-hover:opacity-100"
                                >
                                    <X size={14} />
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

    useEffect(() => {
        function onKeyDown(e) {
            if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [onClose]);

    async function updateField(field, value) {
        await api.patch(`/api/tasks/${task.id}`, { [field]: value || null });
        onChange();
    }

    return (
        <div className="fixed inset-0 z-50">
            <div className="absolute inset-0 bg-ink/20 drawer-overlay" onClick={onClose} />
            <div className="absolute right-0 top-0 h-full w-[600px] max-w-[95vw] bg-white shadow-xl flex flex-col drawer-panel">
                <div className="flex items-center justify-between px-6 py-4 border-b border-border">
                    <select
                        value={task.status}
                        onChange={(e) => updateField('status', e.target.value)}
                        className="text-sm font-medium border border-border rounded px-2 py-1"
                    >
                        {TASK_STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                    </select>
                    <button onClick={onClose} className="text-sage hover:text-ink px-1">
                        <X size={20} />
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto px-6 py-4">
                    <input
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        onBlur={() => title !== task.title && updateField('title', title)}
                        className="text-xl font-semibold w-full mb-4 border border-transparent hover:border-border focus:border-border rounded px-1 -mx-1 focus:outline-none"
                    />

                    <div className="text-sm mb-6 pb-4 border-b border-border">
                        <div className="text-xs font-semibold text-sage mb-1">Assignee</div>
                        <select
                            value={task.assignee ?? ''}
                            onChange={(e) => updateField('assignee', e.target.value)}
                            className="border border-border rounded px-2 py-1 text-sm"
                        >
                            <option value="">Unassigned</option>
                            {teamNames.map((name) => <option key={name} value={name}>{name}</option>)}
                        </select>
                    </div>

                    <div className="mb-6">
                        <div className="text-xs font-semibold text-sage mb-2">Description</div>
                        <textarea
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            onBlur={() => description !== (task.description ?? '') && updateField('description', description)}
                            rows={6}
                            placeholder="Add a description…"
                            className="w-full border border-border rounded px-3 py-2 text-sm"
                        />
                    </div>

                    <SubtasksSection task={task} onChange={onChange} />

                    <FilesSection task={task} onChange={onChange} />

                    <div className="mb-6">
                        <div className="text-xs font-semibold text-sage mb-2">Due date</div>
                        <input
                            type="date"
                            value={task.due_date ? task.due_date.slice(0, 10) : ''}
                            onChange={(e) => updateField('due_date', e.target.value)}
                            className="border border-border rounded px-3 py-2 text-sm"
                        />
                    </div>

                    <div className="text-xs text-sage">Created {formatDate(task.created_at)}</div>
                </div>
            </div>
        </div>
    );
}

function TasksTab({ project }) {
    const [title, setTitle] = useState('');
    const [selectedTaskId, setSelectedTaskId] = useState(null);
    const teamNames = project.team_names || [];
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
            <form onSubmit={addTask} className="flex gap-2 mb-4">
                <input
                    placeholder="New task"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    className="border border-border rounded px-3 py-2 text-sm flex-1"
                />
                <button type="submit" className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded">Add</button>
            </form>
            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {project.tasks.length === 0 ? (
                    <EmptyState text="No tasks yet." />
                ) : (
                    <>
                        <div className="grid grid-cols-12 gap-2 px-4 py-2 border-b border-border text-xs font-medium text-sage">
                            <div className="col-span-5">Task</div>
                            <div className="col-span-3">Assignee</div>
                            <div className="col-span-2">Due date</div>
                            <div className="col-span-2 text-right">Status</div>
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
            className="w-full flex items-center justify-between gap-2 px-4 py-3 border-b border-border last:border-b-0 text-sm text-left group hover:bg-paper"
        >
            <span className="truncate font-medium">{note.title || 'Untitled note'}</span>
            <span className="flex items-center gap-2 flex-shrink-0">
                <span className="text-xs text-sage">{formatDate(note.updated_at)}</span>
                <span className="w-7 h-7 flex items-center justify-center rounded border border-border text-sage opacity-0 group-hover:opacity-100 transition-opacity">
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

    useEffect(() => {
        if (note) {
            setTitle(note.title ?? '');
            setBody(note.body ?? '');
        }
    }, [note?.id]);

    useEffect(() => {
        function onKeyDown(e) {
            if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [onClose]);

    // Flush any pending debounced body save if the drawer closes mid-type,
    // so the last few keystrokes aren't silently dropped.
    useEffect(() => () => clearTimeout(bodySaveTimeout.current), []);

    async function updateField(field, value) {
        await api.patch(`/api/notes/${note.id}`, { [field]: value || null });
        onChange();
    }

    function handleBodyChange(value) {
        setBody(value);
        clearTimeout(bodySaveTimeout.current);
        // Local state already renders the change instantly -- debounce the
        // network save (and the resulting project reload) so typing
        // doesn't fire a request, and a resync, on every keystroke.
        bodySaveTimeout.current = setTimeout(() => updateField('body', value), 600);
    }

    async function remove() {
        await api.delete(`/api/notes/${note.id}`);
        onClose();
        onChange();
    }

    return (
        <div className="fixed inset-0 z-50">
            <div className="absolute inset-0 bg-ink/20 drawer-overlay" onClick={onClose} />
            <div className="absolute right-0 top-0 h-full w-[600px] max-w-[95vw] bg-white shadow-xl flex flex-col drawer-panel">
                <div className="flex items-center justify-between px-6 py-4 border-b border-border">
                    <span className="text-xs text-sage">Edited {formatDate(note.updated_at)}</span>
                    <div className="flex items-center gap-3">
                        <button onClick={remove} title="Delete note" className="text-sage hover:text-brick px-1">
                            <Trash size={18} />
                        </button>
                        <button onClick={onClose} className="text-sage hover:text-ink px-1">
                            <X size={20} />
                        </button>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto px-6 py-4">
                    <input
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        onBlur={() => title !== (note.title ?? '') && updateField('title', title)}
                        placeholder="Untitled note"
                        className="text-xl font-semibold w-full mb-4 border border-transparent hover:border-border focus:border-border rounded px-1 -mx-1 focus:outline-none"
                    />

                    <RichTextEditor value={body} onChange={handleBodyChange} />
                </div>
            </div>
        </div>
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
            <div className="flex justify-end mb-4">
                <button
                    onClick={createNote}
                    disabled={creating}
                    className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50"
                >
                    + New note
                </button>
            </div>
            <div className="bg-white rounded-lg border border-border overflow-hidden">
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
    const [form, setForm] = useState({ subject: '', body: '' });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const clientEmail = project.company.email;

    async function send(e) {
        e.preventDefault();
        if (!form.subject || !form.body) return;
        setSaving(true);
        setError('');
        try {
            await api.post(`/api/projects/${project.id}/messages`, form);
            setForm({ subject: '', body: '' });
            reload();
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <div>
            <form onSubmit={send} className="bg-white rounded-lg border border-border p-4 mb-4">
                {!clientEmail && (
                    <div className="text-sm text-brick mb-2">
                        This client has no email on file — add one on the client page before sending.
                    </div>
                )}
                <div className="text-xs text-sage mb-2">To: {clientEmail ?? '—'}</div>
                <input
                    placeholder="Subject"
                    value={form.subject}
                    onChange={(e) => setForm({ ...form, subject: e.target.value })}
                    className="border border-border rounded px-3 py-2 text-sm w-full mb-2"
                />
                <textarea
                    placeholder="Message…"
                    value={form.body}
                    onChange={(e) => setForm({ ...form, body: e.target.value })}
                    rows={4}
                    className="border border-border rounded px-3 py-2 text-sm w-full mb-2"
                />
                {error && <div className="text-sm text-brick mb-2">{error}</div>}
                <div className="flex justify-end">
                    <button type="submit" disabled={saving || !clientEmail} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">
                        Send email
                    </button>
                </div>
            </form>

            {project.messages.length === 0 ? (
                <EmptyState text="No messages yet." />
            ) : (
                <div className="space-y-3">
                    {project.messages.map((message) => (
                        <div key={message.id} className="bg-white rounded-lg border border-border p-4">
                            <div className="flex items-center justify-between mb-1">
                                <span className="text-sm font-medium">{message.subject}</span>
                                <span className="text-xs text-sage">{formatDate(message.sent_at)}</span>
                            </div>
                            <div className="text-xs text-sage mb-2">To: {message.to_email}</div>
                            <div className="text-sm whitespace-pre-wrap">{message.body}</div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function TimeEntryRow({ entry, onOpen }) {
    return (
        <div className="grid grid-cols-12 gap-2 items-center px-4 py-2 border-b border-border last:border-b-0 text-sm group">
            <div className="col-span-2 text-sage">{formatDate(entry.date)}</div>
            <div className="col-span-2 tabular-nums">{entry.hours}h</div>
            <div className="col-span-6 flex items-center gap-2">
                <span className="truncate text-sage">{entry.task ? entry.task.title : entry.note || '—'}</span>
                <button
                    onClick={() => onOpen(entry.id)}
                    title="Open entry"
                    className="flex-shrink-0 w-7 h-7 flex items-center justify-center rounded border border-border text-sage opacity-0 group-hover:opacity-100 hover:text-ink hover:border-ink transition-opacity"
                >
                    <CaretRight size={14} weight="bold" />
                </button>
            </div>
            <div className="col-span-2 text-right">
                {entry.billed ? <Badge tone="pine" label="Billed" /> : <Badge tone="neutral" label="Unbilled" />}
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

    useEffect(() => {
        function onKeyDown(e) {
            if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [onClose]);

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
        <div className="fixed inset-0 z-50">
            <div className="absolute inset-0 bg-ink/20 drawer-overlay" onClick={onClose} />
            <div className="absolute right-0 top-0 h-full w-[600px] max-w-[95vw] bg-white shadow-xl flex flex-col drawer-panel">
                <div className="flex items-center justify-between px-6 py-4 border-b border-border">
                    {entry.billed ? <Badge tone="pine" label="Billed" /> : <Badge tone="neutral" label="Unbilled" />}
                    <div className="flex items-center gap-3">
                        <button onClick={remove} title="Delete entry" className="text-sage hover:text-brick px-1">
                            <Trash size={18} />
                        </button>
                        <button onClick={onClose} className="text-sage hover:text-ink px-1">
                            <X size={20} />
                        </button>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto px-6 py-4">
                    <div className="grid grid-cols-2 gap-3 mb-6 pb-4 border-b border-border">
                        <div>
                            <div className="text-xs font-semibold text-sage mb-1">Date</div>
                            <input
                                type="date"
                                value={entry.date.slice(0, 10)}
                                onChange={(e) => updateField('date', e.target.value)}
                                className="border border-border rounded px-2 py-1 text-sm w-full"
                            />
                        </div>
                        <div>
                            <div className="text-xs font-semibold text-sage mb-1">Hours</div>
                            <input
                                type="number"
                                min="0.25"
                                step="0.25"
                                value={hours}
                                onChange={(e) => setHours(e.target.value)}
                                onBlur={() => Number(hours) !== Number(entry.hours) && updateField('hours', hours)}
                                className="border border-border rounded px-2 py-1 text-sm w-full tabular-nums"
                            />
                        </div>
                    </div>

                    <div className="mb-6">
                        <div className="text-xs font-semibold text-sage mb-1">Task</div>
                        <select
                            value={entry.task_id ?? ''}
                            onChange={(e) => updateField('task_id', e.target.value || null)}
                            className="border border-border rounded px-2 py-1 text-sm w-full"
                        >
                            <option value="">No task</option>
                            {tasks.map((t) => <option key={t.id} value={t.id}>{t.title}</option>)}
                        </select>
                    </div>

                    <div className="mb-6">
                        <div className="text-xs font-semibold text-sage mb-2">Note</div>
                        <AutoResizeTextarea
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            onBlur={() => note !== (entry.note ?? '') && updateField('note', note)}
                            placeholder="Add a note…"
                            className="w-full border border-border rounded px-3 py-2 text-sm"
                        />
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={entry.billable}
                            disabled={entry.billed}
                            onChange={(e) => updateField('billable', e.target.checked)}
                        />
                        Billable
                    </label>
                </div>
            </div>
        </div>
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
            <form onSubmit={logTime} className="bg-white rounded-lg border border-border p-4 mb-4 grid grid-cols-4 gap-2">
                <input required type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <select value={form.task_id} onChange={(e) => setForm({ ...form, task_id: e.target.value })} className="border border-border rounded px-3 py-2 text-sm">
                    <option value="">No task</option>
                    {project.tasks.map((t) => <option key={t.id} value={t.id}>{t.title}</option>)}
                </select>
                <input required type="number" min="0.25" step="0.25" placeholder="Hours" value={form.hours} onChange={(e) => setForm({ ...form, hours: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <input placeholder="Note" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <button type="submit" disabled={saving} className="col-span-4 bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50 justify-self-end w-fit">Log time</button>
            </form>
            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {project.time_entries.length === 0 ? (
                    <EmptyState text="No time logged yet." />
                ) : (
                    <>
                        <div className="grid grid-cols-12 gap-2 px-4 py-2 border-b border-border text-xs font-medium text-sage">
                            <div className="col-span-2">Date</div>
                            <div className="col-span-2">Hours</div>
                            <div className="col-span-6">Task / Note</div>
                            <div className="col-span-2 text-right">Status</div>
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
            <div className="flex justify-end mb-4">
                <Link
                    href={`/proposals/create?company_id=${project.company_id}&project_id=${project.id}`}
                    className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded"
                >
                    New proposal
                </Link>
            </div>

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {project.proposals.length === 0 ? (
                    <EmptyState text="No proposals for this project yet." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Title</th>
                                <th className="px-4 py-2 font-medium">Estimate</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {project.proposals.map((proposal) => (
                                <tr key={proposal.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-3">
                                        <Link href={`/proposals/${proposal.id}/edit`} className="hover:underline">
                                            {proposal.title}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 tabular-nums">
                                        {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                                    </td>
                                    <td className="px-4 py-3"><ProposalStatusBadge proposal={proposal} /></td>
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
        amount: parseFloat(item.quantity) * parseFloat(item.rate),
        service_id: item.service_id ? String(item.service_id) : '',
    }));
    const proposalTotal = items.reduce((s, i) => s + i.amount, 0);
    const scale = proposalTotal > 0 && remaining < proposalTotal ? Math.max(remaining, 0) / proposalTotal : 1;
    return items.map((item) => ({ ...item, amount: (item.amount * scale).toFixed(2) }));
}

function emptyInvoiceForm() {
    return { proposal_id: '', items: [{ description: '', amount: '' }], surcharge: false };
}

function BillingTab({ project }) {
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(emptyInvoiceForm());
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [copiedInvoiceId, setCopiedInvoiceId] = useState(null);
    const budget = parseFloat(project.budget) || 0;
    const totalInvoiced = project.invoices.reduce((s, inv) => s + invoiceTotal(inv.items, inv.surcharge), 0);
    const remaining = budget - totalInvoiced;
    const proposalsWithItems = project.proposals.filter((p) => p.items.length > 0);
    const selectedProposal = proposalsWithItems.find((p) => String(p.id) === form.proposal_id);
    const selectedProposalTotal = selectedProposal
        ? selectedProposal.items.reduce((s, i) => s + parseFloat(i.quantity) * parseFloat(i.rate), 0)
        : 0;
    const wasScaledToRemaining = selectedProposal && remaining < selectedProposalTotal;

    function openForm() {
        // Start from the most recently accepted proposal's line items when
        // there's exactly one to choose from -- otherwise let the user pick.
        const accepted = proposalsWithItems.filter((p) => p.status === 'accepted');
        if (accepted.length === 1) {
            setForm({ proposal_id: String(accepted[0].id), items: proposalToInvoiceItems(accepted[0], remaining), surcharge: false });
        } else {
            setForm(emptyInvoiceForm());
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
            setError('Add at least one line item with a description and amount.');
            return;
        }
        setSaving(true);
        setError('');
        try {
            await api.post(`/api/companies/${project.company_id}/invoices`, {
                project_id: project.id,
                surcharge: form.surcharge,
                items: validItems,
            });
            setForm(emptyInvoiceForm());
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

    async function markInvoicePaid(invoice) {
        await api.post(`/api/invoices/${invoice.id}/mark-paid`);
        reload();
    }

    function copyInvoiceLink(invoice) {
        navigator.clipboard.writeText(`${window.location.origin}/i/${invoice.public_token}`);
        setCopiedInvoiceId(invoice.id);
        setTimeout(() => setCopiedInvoiceId((id) => (id === invoice.id ? null : id)), 1500);
    }

    return (
        <div>
            <div className="flex items-center justify-between mb-4">
                {budget > 0 ? (
                    <div className="text-sm text-sage">
                        Budget <span className="tabular-nums font-medium text-ink">{formatCurrency(budget)}</span>
                        {' · '}
                        Remaining <span className={`tabular-nums font-medium ${remaining < 0 ? 'text-brick' : 'text-ink'}`}>{formatCurrency(remaining)}</span>
                    </div>
                ) : <div />}
                <button onClick={() => (showForm ? setShowForm(false) : openForm())} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">
                    {showForm ? 'Cancel' : 'New invoice'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={createInvoice} className="bg-white rounded-lg border border-border p-4 mb-4">
                    {proposalsWithItems.length > 0 && (
                        <div className="mb-3">
                            <select
                                value={form.proposal_id}
                                onChange={(e) => copyFromProposal(e.target.value)}
                                className="border border-border rounded px-3 py-2 text-sm w-full"
                            >
                                <option value="">Copy line items from a proposal…</option>
                                {proposalsWithItems.map((p) => (
                                    <option key={p.id} value={p.id}>{p.title} ({formatCurrency(p.estimate_amount)})</option>
                                ))}
                            </select>
                            {wasScaledToRemaining && (
                                <div className="text-xs text-sage mt-1">
                                    Scaled to the {formatCurrency(Math.max(remaining, 0))} left in the budget.
                                </div>
                            )}
                        </div>
                    )}

                    <div className="mb-3">
                        {form.items.map((item, idx) => (
                            <div key={idx} className="flex gap-2 mb-2">
                                <input
                                    placeholder="Description"
                                    value={item.description}
                                    onChange={(e) => updateItem(idx, 'description', e.target.value)}
                                    className="border border-border rounded px-3 py-2 text-sm flex-1"
                                />
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="Amount"
                                    value={item.amount}
                                    onChange={(e) => updateItem(idx, 'amount', e.target.value)}
                                    className="border border-border rounded px-3 py-2 text-sm tabular-nums w-28"
                                />
                                {form.items.length > 1 && (
                                    <button type="button" onClick={() => removeItemRow(idx)} className="text-sm px-2 text-brick">Remove</button>
                                )}
                            </div>
                        ))}
                        <button type="button" onClick={addItemRow} className="text-sm font-medium text-brass">+ Add line item</button>
                    </div>

                    <label className="flex items-center gap-2 text-sm mb-2">
                        <input type="checkbox" checked={form.surcharge} onChange={(e) => setForm({ ...form, surcharge: e.target.checked })} />
                        Client covers card processing fee (3%)
                    </label>
                    {error && <div className="text-sm text-brick mb-2">{error}</div>}
                    <div className="flex justify-end">
                        <button type="submit" disabled={saving} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Create draft invoice</button>
                    </div>
                </form>
            )}

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {project.invoices.length === 0 ? (
                    <EmptyState text="No invoices for this project yet." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Issued</th>
                                <th className="px-4 py-2 font-medium">Total</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                                <th className="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {project.invoices.map((invoice) => (
                                <tr key={invoice.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-2">
                                        <Link href={`/invoices/${invoice.id}`} className="hover:underline">
                                            {formatDate(invoice.issued_on)}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2 tabular-nums">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</td>
                                    <td className="px-4 py-2"><InvoiceStatusBadge invoice={invoice} /></td>
                                    <td className="px-4 py-2 text-right">
                                        <div className="flex items-center justify-end gap-3">
                                            <a href={`/i/${invoice.public_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="text-sage hover:text-ink">
                                                <Eye size={16} />
                                            </a>
                                            {invoice.status === 'draft' && (
                                                <Link href={`/invoices/${invoice.id}`} title="Edit" className="text-pine hover:text-pine/70">
                                                    <PencilSimple size={16} />
                                                </Link>
                                            )}
                                            {invoice.status === 'draft' && (
                                                <button onClick={() => sendInvoice(invoice)} title="Send" className="text-brass hover:text-brass/70">
                                                    <PaperPlaneTilt size={16} />
                                                </button>
                                            )}
                                            {invoice.status !== 'draft' && (
                                                <button onClick={() => copyInvoiceLink(invoice)} title={copiedInvoiceId === invoice.id ? 'Copied!' : 'Copy link'} className="text-sage hover:text-ink">
                                                    {copiedInvoiceId === invoice.id ? <Check size={16} /> : <Copy size={16} />}
                                                </button>
                                            )}
                                            {invoice.status === 'sent' && (
                                                <button onClick={() => markInvoicePaid(invoice)} title="Mark paid" className="text-pine hover:text-pine/70">
                                                    <CheckCircle size={16} />
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
    const expenses = project.transactions.filter((t) => t.type === 'expense');
    const [form, setForm] = useState({ amount: '', category: '', occurred_on: new Date().toISOString().slice(0, 10), description: '' });
    const [saving, setSaving] = useState(false);

    async function addExpense(e) {
        e.preventDefault();
        if (!(parseFloat(form.amount) > 0) || !form.occurred_on) return;
        setSaving(true);
        try {
            await api.post('/api/transactions', { ...form, type: 'expense', project_id: project.id });
            setForm({ amount: '', category: '', occurred_on: new Date().toISOString().slice(0, 10), description: '' });
            reload();
        } finally {
            setSaving(false);
        }
    }

    const total = expenses.reduce((s, t) => s + parseFloat(t.amount), 0);

    return (
        <div>
            <form onSubmit={addExpense} className="bg-white rounded-lg border border-border p-4 mb-4 grid grid-cols-2 gap-2">
                <input required type="number" min="0.01" step="0.01" placeholder="Amount" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="border border-border rounded px-3 py-2 text-sm tabular-nums" />
                <input placeholder="Category" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <input required type="date" value={form.occurred_on} onChange={(e) => setForm({ ...form, occurred_on: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <input placeholder="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <button type="submit" disabled={saving} className="col-span-2 bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50 justify-self-end w-fit">Add expense</button>
            </form>
            <div className="text-sm text-sage mb-2">Total expenses: <span className="tabular-nums text-brick">{formatCurrency(total)}</span></div>
            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {expenses.length === 0 ? (
                    <EmptyState text="No expenses logged for this project." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Date</th>
                                <th className="px-4 py-2 font-medium">Category</th>
                                <th className="px-4 py-2 font-medium">Description</th>
                                <th className="px-4 py-2 font-medium text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {expenses.map((t) => (
                                <tr key={t.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-2">{formatDate(t.occurred_on)}</td>
                                    <td className="px-4 py-2 text-sage">{t.category ?? '—'}</td>
                                    <td className="px-4 py-2 text-sage">{t.description}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-brick">{formatCurrency(t.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </div>
    );
}

function TeamTab({ project }) {
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
            <form onSubmit={add} className="flex gap-2 mb-4">
                <input
                    placeholder="Add team member name"
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    className="border border-border rounded px-3 py-2 text-sm flex-1"
                />
                <button type="submit" disabled={saving} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Add</button>
            </form>
            {names.length === 0 ? (
                <EmptyState text="No one assigned yet." />
            ) : (
                <div className="flex flex-wrap gap-2">
                    {names.map((name) => (
                        <span key={name} className="inline-flex items-center gap-2 bg-white border border-border rounded-full px-3 py-1 text-sm">
                            {name}
                            <button onClick={() => remove(name)} className="text-sage hover:text-brick">
                                <X size={14} />
                            </button>
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function ProjectsShow({ project }) {
    const [tab, setTab] = useState('Overview');

    return (
        <AppLayout>
            <Head title={project.name} />
            <div className="mb-1">
                <Link href="/projects" className="text-sm text-sage hover:underline inline-flex items-center gap-1">
                    <ArrowLeft size={14} /> Projects
                </Link>
            </div>
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">{project.name}</h1>
                <ProjectStatusBadge project={project} />
            </div>
            <p className="text-sm text-sage mb-6">
                <Link href={`/clients/${project.company.id}`} className="hover:underline">{project.company.name}</Link>
            </p>

            <TabBar tab={tab} setTab={setTab} />

            {tab === 'Overview' && <OverviewTab project={project} />}
            {tab === 'Tasks' && <TasksTab project={project} />}
            {tab === 'Notes' && <NotesTab project={project} />}
            {tab === 'Messages' && <MessagesTab project={project} />}
            {tab === 'Time' && <TimeTab project={project} />}
            {tab === 'Proposals' && <ProposalsTab project={project} />}
            {tab === 'Billing' && <BillingTab project={project} />}
            {tab === 'Expenses' && <ExpensesTab project={project} />}
            {tab === 'Team' && <TeamTab project={project} />}
        </AppLayout>
    );
}

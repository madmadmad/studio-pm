import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import { ProjectStatusBadge, TaskStatusBadge, InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';

const TABS = ['Overview', 'Tasks', 'Notes', 'Messages', 'Time', 'Billing', 'Expenses', 'Team'];
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
    const totalHours = project.timeEntries.reduce((s, e) => s + parseFloat(e.hours), 0);
    const unbilledHours = project.timeEntries.filter((e) => !e.billed).reduce((s, e) => s + parseFloat(e.hours), 0);
    const doneTasks = project.tasks.filter((t) => t.status === 'done').length;
    const totalInvoiced = project.invoices.reduce((s, inv) => s + invoiceTotal(inv.items, inv.surcharge), 0);

    return (
        <div>
            {project.description && <p className="text-sm text-sage mb-6">{project.description}</p>}
            <div className="grid grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-lg border border-border p-4">
                    <div className="text-xs text-sage mb-1">Tasks</div>
                    <div className="font-mono text-xl">{doneTasks}/{project.tasks.length}</div>
                </div>
                <div className="bg-white rounded-lg border border-border p-4">
                    <div className="text-xs text-sage mb-1">Hours logged</div>
                    <div className="font-mono text-xl">{totalHours}h</div>
                </div>
                <div className="bg-white rounded-lg border border-border p-4">
                    <div className="text-xs text-sage mb-1">Unbilled hours</div>
                    <div className="font-mono text-xl">{unbilledHours}h</div>
                </div>
                <div className="bg-white rounded-lg border border-border p-4">
                    <div className="text-xs text-sage mb-1">Total invoiced</div>
                    <div className="font-mono text-xl">{formatCurrency(totalInvoiced)}</div>
                </div>
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

function TasksTab({ project }) {
    const [title, setTitle] = useState('');

    async function addTask(e) {
        e.preventDefault();
        if (!title) return;
        await api.post(`/api/projects/${project.id}/tasks`, { title });
        setTitle('');
        reload();
    }

    async function cycleStatus(task) {
        const order = ['todo', 'in_progress', 'done'];
        const next = order[(order.indexOf(task.status) + 1) % order.length];
        await api.patch(`/api/tasks/${task.id}`, { status: next });
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
                <button type="submit" className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">Add</button>
            </form>
            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {project.tasks.length === 0 ? (
                    <EmptyState text="No tasks yet." />
                ) : (
                    <ul className="text-sm divide-y divide-border">
                        {project.tasks.map((task) => (
                            <li key={task.id} className="px-4 py-3 flex items-center justify-between">
                                <span>{task.title}</span>
                                <button onClick={() => cycleStatus(task)}>
                                    <TaskStatusBadge task={task} />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

function NotesTab({ project }) {
    const [body, setBody] = useState('');
    const [saving, setSaving] = useState(false);

    async function addNote(e) {
        e.preventDefault();
        if (!body.trim()) return;
        setSaving(true);
        try {
            await api.post(`/api/projects/${project.id}/notes`, { body });
            setBody('');
            reload();
        } finally {
            setSaving(false);
        }
    }

    return (
        <div>
            <form onSubmit={addNote} className="bg-white rounded-lg border border-border p-4 mb-4">
                <textarea
                    placeholder="Add an internal note…"
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    rows={3}
                    className="border border-border rounded px-3 py-2 text-sm w-full mb-2"
                />
                <div className="flex justify-end">
                    <button type="submit" disabled={saving} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Add note</button>
                </div>
            </form>
            {project.notes.length === 0 ? (
                <EmptyState text="No notes yet." />
            ) : (
                <div className="space-y-3">
                    {project.notes.map((note) => (
                        <div key={note.id} className="bg-white rounded-lg border border-border p-4">
                            <div className="text-xs text-sage mb-1">{formatDate(note.created_at)}</div>
                            <div className="text-sm whitespace-pre-wrap">{note.body}</div>
                        </div>
                    ))}
                </div>
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

function TimeTab({ project }) {
    const [form, setForm] = useState({ date: '', hours: '', note: '' });
    const [saving, setSaving] = useState(false);

    async function logTime(e) {
        e.preventDefault();
        if (!form.date || !form.hours) return;
        setSaving(true);
        try {
            await api.post('/api/time-entries', {
                company_id: project.company_id,
                project_id: project.id,
                date: form.date,
                hours: form.hours,
                note: form.note,
            });
            setForm({ date: '', hours: '', note: '' });
            reload();
        } finally {
            setSaving(false);
        }
    }

    return (
        <div>
            <form onSubmit={logTime} className="bg-white rounded-lg border border-border p-4 mb-4 grid grid-cols-3 gap-2">
                <input required type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <input required type="number" min="0.25" step="0.25" placeholder="Hours" value={form.hours} onChange={(e) => setForm({ ...form, hours: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <input placeholder="Note" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <button type="submit" disabled={saving} className="col-span-3 bg-ink text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50 justify-self-end w-fit">Log time</button>
            </form>
            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {project.timeEntries.length === 0 ? (
                    <EmptyState text="No time logged yet." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Date</th>
                                <th className="px-4 py-2 font-medium">Hours</th>
                                <th className="px-4 py-2 font-medium">Note</th>
                                <th className="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {project.timeEntries.map((entry) => (
                                <tr key={entry.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-2">{formatDate(entry.date)}</td>
                                    <td className="px-4 py-2 font-mono">{entry.hours}h</td>
                                    <td className="px-4 py-2 text-sage">{entry.note}</td>
                                    <td className="px-4 py-2 text-right">
                                        {entry.billed ? <Badge tone="pine" label="Billed" /> : <Badge tone="neutral" label="Unbilled" />}
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

function BillingTab({ project }) {
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState({ description: '', amount: '', surcharge: false });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    async function createInvoice(e) {
        e.preventDefault();
        if (!form.description || !(parseFloat(form.amount) > 0)) {
            setError('Description and a positive amount are required.');
            return;
        }
        setSaving(true);
        setError('');
        try {
            await api.post(`/api/companies/${project.company_id}/invoices`, {
                project_id: project.id,
                surcharge: form.surcharge,
                items: [{ description: form.description, amount: form.amount }],
            });
            setForm({ description: '', amount: '', surcharge: false });
            setShowForm(false);
            reload();
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <div>
            <div className="flex justify-end mb-4">
                <button onClick={() => setShowForm(!showForm)} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">
                    {showForm ? 'Cancel' : 'New invoice'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={createInvoice} className="bg-white rounded-lg border border-border p-4 mb-4">
                    <input
                        placeholder="Description (e.g. Monthly retainer — September)"
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm w-full mb-2"
                    />
                    <input
                        type="number"
                        min="0.01"
                        step="0.01"
                        placeholder="Amount"
                        value={form.amount}
                        onChange={(e) => setForm({ ...form, amount: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm font-mono w-full mb-2"
                    />
                    <label className="flex items-center gap-2 text-sm mb-2">
                        <input type="checkbox" checked={form.surcharge} onChange={(e) => setForm({ ...form, surcharge: e.target.checked })} />
                        Client covers card processing fee (3%)
                    </label>
                    {error && <div className="text-sm text-brick mb-2">{error}</div>}
                    <div className="flex justify-end">
                        <button type="submit" disabled={saving} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Create draft invoice</button>
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
                                    <td className="px-4 py-2 font-mono">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</td>
                                    <td className="px-4 py-2"><InvoiceStatusBadge invoice={invoice} /></td>
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
                <input required type="number" min="0.01" step="0.01" placeholder="Amount" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="border border-border rounded px-3 py-2 text-sm font-mono" />
                <input placeholder="Category" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <input required type="date" value={form.occurred_on} onChange={(e) => setForm({ ...form, occurred_on: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <input placeholder="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                <button type="submit" disabled={saving} className="col-span-2 bg-ink text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50 justify-self-end w-fit">Add expense</button>
            </form>
            <div className="text-sm text-sage mb-2">Total expenses: <span className="font-mono text-brick">{formatCurrency(total)}</span></div>
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
                                    <td className="px-4 py-2 text-right font-mono text-brick">{formatCurrency(t.amount)}</td>
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
                <button type="submit" disabled={saving} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Add</button>
            </form>
            {names.length === 0 ? (
                <EmptyState text="No one assigned yet." />
            ) : (
                <div className="flex flex-wrap gap-2">
                    {names.map((name) => (
                        <span key={name} className="inline-flex items-center gap-2 bg-white border border-border rounded-full px-3 py-1 text-sm">
                            {name}
                            <button onClick={() => remove(name)} className="text-sage hover:text-brick">&times;</button>
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
                <Link href="/projects" className="text-sm text-sage hover:underline">&larr; Projects</Link>
            </div>
            <div className="flex items-center justify-between mb-1">
                <h1 className="text-2xl font-semibold">{project.name}</h1>
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
            {tab === 'Billing' && <BillingTab project={project} />}
            {tab === 'Expenses' && <ExpensesTab project={project} />}
            {tab === 'Team' && <TeamTab project={project} />}
        </AppLayout>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft } from '@phosphor-icons/react';
import PortalLayout from '../../../Layouts/PortalLayout';
import EmptyState from '../../../Components/EmptyState';
import { ProjectStatusBadge, TaskStatusBadge, InvoiceStatusBadge, ProposalStatusBadge } from '../../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceTotal } from '../../../lib/format';
import { api } from '../../../lib/api';

const TABS = ['Overview', 'Tasks', 'Messages', 'Proposals', 'Invoices', 'Team'];

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
                        tab === t ? 'border-gunmetal text-gunmetal' : 'border-transparent text-shadow-grey'
                    }`}
                >
                    {t}
                </button>
            ))}
        </div>
    );
}

function OverviewTab({ project }) {
    return (
        <div className="bg-white rounded-lg border border-border p-4">
            <div className="text-sm text-shadow-grey mb-1">Status</div>
            <ProjectStatusBadge project={project} />
            {project.description && (
                <p className="text-sm mt-4 whitespace-pre-wrap">{project.description}</p>
            )}
        </div>
    );
}

function NewTaskForm({ project }) {
    const [title, setTitle] = useState('');
    const [saving, setSaving] = useState(false);

    async function submit(e) {
        e.preventDefault();
        if (!title.trim()) return;
        setSaving(true);
        try {
            await api.post(`/api/portal/projects/${project.id}/tasks`, { title });
            setTitle('');
            reload();
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex gap-2 mb-4">
            <input
                placeholder="New task title"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                className="border border-border rounded px-3 py-2 text-sm flex-1"
            />
            <button type="submit" disabled={saving} className="bg-fern text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Add task</button>
        </form>
    );
}

function TaskRow({ task }) {
    const [status, setStatus] = useState(task.status);

    async function cycleStatus() {
        const order = ['todo', 'in_progress', 'done'];
        const next = order[(order.indexOf(status) + 1) % order.length];
        setStatus(next);
        await api.patch(`/api/portal/tasks/${task.id}`, { status: next });
    }

    return (
        <div className="flex items-center justify-between px-4 py-3 border-b border-border last:border-b-0">
            <div>
                <div className="text-sm font-medium">{task.title}</div>
                {task.due_date && <div className="text-xs text-shadow-grey">Due {formatDate(task.due_date)}</div>}
            </div>
            <button onClick={cycleStatus}>
                <TaskStatusBadge task={{ status }} />
            </button>
        </div>
    );
}

function TasksTab({ project }) {
    return (
        <div>
            <NewTaskForm project={project} />
            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {project.tasks.length === 0 ? (
                    <EmptyState text="No tasks yet." />
                ) : (
                    project.tasks.map((task) => <TaskRow key={task.id} task={task} />)
                )}
            </div>
        </div>
    );
}

function MessageThread({ message }) {
    const [replying, setReplying] = useState(false);
    const [body, setBody] = useState('');
    const [saving, setSaving] = useState(false);
    const senderName = message.sender_contact?.name ?? message.sender_user?.name ?? 'Studio';

    async function submitReply(e) {
        e.preventDefault();
        if (!body.trim()) return;
        setSaving(true);
        try {
            await api.post(`/api/portal/projects/${message.project_id}/messages`, {
                parent_id: message.id,
                body,
            });
            setBody('');
            setReplying(false);
            reload();
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="border-b border-border last:border-b-0 p-4">
            <div className="flex items-center justify-between mb-1">
                <div className="text-sm font-medium">{message.subject ?? '(no subject)'}</div>
                <div className="text-xs text-shadow-grey">{formatDate(message.sent_at)}</div>
            </div>
            <div className="text-xs text-shadow-grey mb-2">{senderName}</div>
            <p className="text-sm whitespace-pre-wrap mb-2">{message.body}</p>

            {(message.replies || []).map((reply) => (
                <div key={reply.id} className="ml-4 pl-3 border-l-2 border-border mt-3">
                    <div className="text-xs text-shadow-grey mb-1">
                        {reply.sender_contact?.name ?? reply.sender_user?.name ?? 'Studio'} &middot; {formatDate(reply.sent_at)}
                    </div>
                    <p className="text-sm whitespace-pre-wrap">{reply.body}</p>
                </div>
            ))}

            {replying ? (
                <form onSubmit={submitReply} className="mt-3 ml-4 flex flex-col gap-2">
                    <textarea
                        autoFocus
                        rows={3}
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        className="border border-border rounded px-3 py-2 text-sm"
                        placeholder="Write a reply..."
                    />
                    <div className="flex gap-2 justify-end">
                        <button type="button" onClick={() => setReplying(false)} className="text-sm px-3 py-1.5 rounded text-shadow-grey">Cancel</button>
                        <button type="submit" disabled={saving} className="bg-fern text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Reply</button>
                    </div>
                </form>
            ) : (
                <button onClick={() => setReplying(true)} className="text-xs text-watermelon hover:underline mt-2 ml-4">Reply</button>
            )}
        </div>
    );
}

function NewMessageForm({ project }) {
    const [showForm, setShowForm] = useState(false);
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [saving, setSaving] = useState(false);

    async function submit(e) {
        e.preventDefault();
        if (!subject.trim() || !body.trim()) return;
        setSaving(true);
        try {
            await api.post(`/api/portal/projects/${project.id}/messages`, { subject, body });
            setSubject('');
            setBody('');
            setShowForm(false);
            reload();
        } finally {
            setSaving(false);
        }
    }

    if (!showForm) {
        return (
            <button onClick={() => setShowForm(true)} className="bg-gunmetal text-white text-sm font-medium px-3 py-1.5 rounded mb-4">
                New message
            </button>
        );
    }

    return (
        <form onSubmit={submit} className="bg-white rounded-lg border border-border p-4 mb-4 flex flex-col gap-2">
            <input required placeholder="Subject" value={subject} onChange={(e) => setSubject(e.target.value)} className="border border-border rounded px-3 py-2 text-sm" />
            <textarea required rows={4} placeholder="Message" value={body} onChange={(e) => setBody(e.target.value)} className="border border-border rounded px-3 py-2 text-sm" />
            <div className="flex gap-2 justify-end">
                <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 rounded text-shadow-grey">Cancel</button>
                <button type="submit" disabled={saving} className="bg-fern text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Send</button>
            </div>
        </form>
    );
}

function MessagesTab({ project }) {
    return (
        <div>
            <NewMessageForm project={project} />
            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {project.messages.length === 0 ? (
                    <EmptyState text="No messages yet." />
                ) : (
                    project.messages.map((message) => <MessageThread key={message.id} message={message} />)
                )}
            </div>
        </div>
    );
}

function ProposalsTab({ project }) {
    return (
        <div className="bg-white rounded-lg border border-border overflow-hidden">
            {project.proposals.length === 0 ? (
                <EmptyState text="No accepted proposals yet." />
            ) : (
                project.proposals.map((proposal) => (
                    <div key={proposal.id} className="flex items-center justify-between px-4 py-3 border-b border-border last:border-b-0">
                        <div>
                            <div className="text-sm font-medium">{proposal.title}</div>
                            <div className="text-xs text-shadow-grey">Accepted {formatDate(proposal.accepted_at)}</div>
                        </div>
                        <div className="flex items-center gap-3">
                            <span className="tabular-nums text-sm">{formatCurrency(proposal.estimate_amount)}</span>
                            <ProposalStatusBadge proposal={proposal} />
                            <a
                                href={`/p/${proposal.accept_token}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="bg-fern text-white text-xs font-bold px-3 py-1.5 rounded hover:bg-fern/90"
                            >
                                Client view
                            </a>
                        </div>
                    </div>
                ))
            )}
        </div>
    );
}

function InvoicesTab({ project }) {
    return (
        <div className="bg-white rounded-lg border border-border overflow-hidden">
            {project.invoices.length === 0 ? (
                <EmptyState text="No invoices yet." />
            ) : (
                project.invoices.map((invoice) => (
                    <div key={invoice.id} className="flex items-center justify-between px-4 py-3 border-b border-border last:border-b-0">
                        <div>
                            <div className="text-sm font-medium">Invoice #{invoice.invoice_number ?? invoice.id}</div>
                            <div className="text-xs text-shadow-grey">Issued {formatDate(invoice.issued_on)} &middot; Due {formatDate(invoice.due_on)}</div>
                        </div>
                        <div className="flex items-center gap-3">
                            <span className="tabular-nums text-sm">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</span>
                            <InvoiceStatusBadge invoice={invoice} />
                            <a
                                href={`/i/${invoice.public_token}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="bg-fern text-white text-xs font-bold px-3 py-1.5 rounded hover:bg-fern/90"
                            >
                                Client view
                            </a>
                        </div>
                    </div>
                ))
            )}
        </div>
    );
}

function TeamTab({ project }) {
    return (
        <div className="bg-white rounded-lg border border-border overflow-hidden">
            {(project.active_users || []).length === 0 ? (
                <EmptyState text="No staff assigned yet." />
            ) : (
                project.active_users.map((user) => (
                    <div key={user.id} className="flex items-center justify-between px-4 py-3 border-b border-border last:border-b-0">
                        <div className="text-sm font-medium">{user.name}</div>
                        <span className="text-xs text-shadow-grey capitalize">{user.role.replace('_', ' ')}</span>
                    </div>
                ))
            )}
        </div>
    );
}

export default function PortalProjectShow({ project }) {
    const [tab, setTab] = useState('Overview');

    return (
        <PortalLayout>
            <Head title={project.name} />
            <div className="mb-1">
                <Link href="/portal" className="text-sm text-shadow-grey hover:underline inline-flex items-center gap-1">
                    <ArrowLeft size={14} /> Your projects
                </Link>
            </div>
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">{project.name}</h1>
                <ProjectStatusBadge project={project} />
            </div>
            <p className="text-sm text-shadow-grey mb-6">{project.company.name}</p>

            <TabBar tab={tab} setTab={setTab} />

            {tab === 'Overview' && <OverviewTab project={project} />}
            {tab === 'Tasks' && <TasksTab project={project} />}
            {tab === 'Messages' && <MessagesTab project={project} />}
            {tab === 'Proposals' && <ProposalsTab project={project} />}
            {tab === 'Invoices' && <InvoicesTab project={project} />}
            {tab === 'Team' && <TeamTab project={project} />}
        </PortalLayout>
    );
}

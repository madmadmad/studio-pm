import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft } from '@phosphor-icons/react';
import PortalLayout from '../../../Layouts/PortalLayout';
import Button from '../../../Components/Button';
import EmptyState from '../../../Components/EmptyState';
import MessagesPanel from '../../../Components/MessagesPanel';
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
        <div className="card card-padded">
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
                className="field flex-1"
            />
            <Button type="submit" variant="confirm" disabled={saving}>Add task</Button>
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
            <div className="card overflow-hidden">
                {project.tasks.length === 0 ? (
                    <EmptyState text="No tasks yet." />
                ) : (
                    project.tasks.map((task) => <TaskRow key={task.id} task={task} />)
                )}
            </div>
        </div>
    );
}

function MessagesTab({ project }) {
    const { props } = usePage();
    const currentContact = props.auth?.user;

    const recipientOptions = [
        ...(project.active_users || []).map((u) => ({ token: `user:${u.id}`, name: u.name, sublabel: 'Team' })),
        ...(project.company.contacts || [])
            .filter((c) => c.has_portal_access && c.id !== currentContact?.id)
            .map((c) => ({ token: `contact:${c.id}`, name: c.name, sublabel: 'Client' })),
    ];

    return (
        <MessagesPanel
            project={project}
            currentActorType="contact"
            currentActorId={currentContact?.id}
            recipientOptions={recipientOptions}
            endpoints={{
                create: `/api/portal/projects/${project.id}/messages`,
                reply: (id) => `/api/portal/messages/${id}/replies`,
                join: (id) => `/api/portal/messages/${id}/join`,
            }}
            onChange={reload}
        />
    );
}

function ProposalsTab({ project }) {
    return (
        <div className="card overflow-hidden">
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
                                className="btn btn-confirm btn-sm"
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
        <div className="card overflow-hidden">
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
                                className="btn btn-confirm btn-sm"
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
        <div className="card overflow-hidden">
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

import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '../../../Layouts/PortalLayout';
import Button from '../../../Components/Button';
import EmptyState from '../../../Components/EmptyState';
import MessagesPanel from '../../../Components/MessagesPanel';
import { ProjectStatusBadge, TaskStatusBadge, InvoiceStatusBadge, ProposalStatusBadge } from '../../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceTotal } from '../../../lib/format';
import { api } from '../../../lib/api';
import PageHeader from '../../../Components/PageHeader';
import TabBar from '../../../Components/TabBar';

const TABS = ['Overview', 'Tasks', 'Messages', 'Proposals', 'Invoices', 'Team'];

function reload() {
    router.reload({ only: ['project'] });
}

function OverviewTab({ project }) {
    return (
        <div className="card card--padded">
            <div className="portal-project__label">Status</div>
            <ProjectStatusBadge project={project} />
            {project.description && (
                <p className="portal-project__description">{project.description}</p>
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
        <form onSubmit={submit} className="inline-form page-section--tight">
            <input
                placeholder="New task title"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                className="input inline-form__grow"
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
        <div className="list-row list-row--static">
            <div>
                <div className="list-row__title">{task.title}</div>
                {task.due_date && <div className="list-row__meta">Due {formatDate(task.due_date)}</div>}
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
            <div className="card card--flush">
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
                update: (id) => `/api/portal/messages/${id}`,
                destroy: (id) => `/api/portal/messages/${id}`,
                attachmentUrl: (id) => `/api/portal/attachments/${id}`,
                attachmentThumbnailUrl: (id) => `/api/portal/attachments/${id}/thumbnail`,
            }}
            onChange={reload}
        />
    );
}

function ProposalsTab({ project }) {
    return (
        <div className="card card--flush">
            {project.proposals.length === 0 ? (
                <EmptyState text="No accepted proposals yet." />
            ) : (
                project.proposals.map((proposal) => (
                    <div key={proposal.id} className="list-row list-row--static">
                        <div>
                            <div className="list-row__title">{proposal.title}</div>
                            <div className="list-row__meta">Accepted {formatDate(proposal.accepted_at)}</div>
                        </div>
                        <div className="list-row__aside">
                            <span className="list-row__amount">{formatCurrency(proposal.estimate_amount)}</span>
                            <ProposalStatusBadge proposal={proposal} />
                            <a
                                href={`/p/${proposal.accept_token}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="btn btn--confirm btn--sm"
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
        <div className="card card--flush">
            {project.invoices.length === 0 ? (
                <EmptyState text="No invoices yet." />
            ) : (
                project.invoices.map((invoice) => (
                    <div key={invoice.id} className="list-row list-row--static">
                        <div>
                            <div className="list-row__title">Invoice #{invoice.invoice_number ?? invoice.id}</div>
                            <div className="list-row__meta">Issued {formatDate(invoice.issued_on)} &middot; Due {formatDate(invoice.due_on)}</div>
                        </div>
                        <div className="list-row__aside">
                            <span className="list-row__amount">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</span>
                            <InvoiceStatusBadge invoice={invoice} />
                            <a
                                href={`/i/${invoice.public_token}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="btn btn--confirm btn--sm"
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
        <div className="card card--flush">
            {(project.active_users || []).length === 0 ? (
                <EmptyState text="No staff assigned yet." />
            ) : (
                project.active_users.map((user) => (
                    <div key={user.id} className="list-row list-row--static">
                        <div className="list-row__title">{user.name}</div>
                        <span className="list-row__meta list-row__meta--capitalize">{user.role.replace('_', ' ')}</span>
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
            <PageHeader
                back={{ href: '/portal', label: 'Your projects' }}
                title={project.name}
                actions={<ProjectStatusBadge project={project} />}
                subtitle={project.company.name}
            />

            <TabBar tabs={TABS} tab={tab} setTab={setTab} />

            {tab === 'Overview' && <OverviewTab project={project} />}
            {tab === 'Tasks' && <TasksTab project={project} />}
            {tab === 'Messages' && <MessagesTab project={project} />}
            {tab === 'Proposals' && <ProposalsTab project={project} />}
            {tab === 'Invoices' && <InvoicesTab project={project} />}
            {tab === 'Team' && <TeamTab project={project} />}
        </PortalLayout>
    );
}

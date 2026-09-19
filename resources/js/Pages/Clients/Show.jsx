import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import { InvoiceStatusBadge, ProposalStatusBadge, ProjectStatusBadge, TaskStatusBadge, CompanyStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';

function reload() {
    router.reload({ only: ['company'] });
}

function DetailsCard({ company }) {
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({
        name: company.name,
        email: company.email ?? '',
        phone: company.phone ?? '',
        address: company.address ?? '',
        default_hourly_rate: company.default_hourly_rate ?? '',
        status: company.status,
    });

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        try {
            await api.patch(`/api/companies/${company.id}`, form);
            setEditing(false);
            reload();
        } finally {
            setSaving(false);
        }
    }

    if (!editing) {
        return (
            <div className="mb-6">
                <div className="flex items-center justify-between mb-1">
                    <h1 className="font-display text-2xl font-semibold">{company.name}</h1>
                    <div className="flex items-center gap-3">
                        <CompanyStatusBadge company={company} />
                        <button onClick={() => setEditing(true)} className="text-sm font-medium text-pine hover:underline">
                            Edit
                        </button>
                    </div>
                </div>
                <p className="text-sm text-sage">
                    {company.email}{company.phone ? ` · ${company.phone}` : ''}
                    {company.default_hourly_rate ? ` · ${formatCurrency(company.default_hourly_rate)}/hr` : ''}
                </p>
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="bg-white rounded-lg border border-border p-4 mb-6 grid grid-cols-2 gap-3">
            <input required placeholder="Client or company name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="border border-border rounded px-3 py-2 text-sm col-span-2" />
            <input type="email" placeholder="Email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
            <input placeholder="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
            <input placeholder="Address" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} className="border border-border rounded px-3 py-2 text-sm col-span-2" />
            <input type="number" min="0" placeholder="Default hourly rate ($)" value={form.default_hourly_rate} onChange={(e) => setForm({ ...form, default_hourly_rate: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
            <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="border border-border rounded px-3 py-2 text-sm">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <div className="flex gap-2 col-span-2 justify-end">
                <button type="button" onClick={() => setEditing(false)} className="text-sm px-3 py-1.5 rounded text-sage">Cancel</button>
                <button type="submit" disabled={saving} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Save</button>
            </div>
        </form>
    );
}

function ContactRoleBadges({ contact }) {
    return (
        <>
            {contact.is_primary && <Badge tone="pine" label="Primary" />}
            {contact.is_billing && <Badge tone="brass" label="Billing" />}
        </>
    );
}

function ContactsCard({ company }) {
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState({ name: '', email: '', phone: '', role: '', is_primary: false, is_billing: false });

    async function submit(e) {
        e.preventDefault();
        if (!form.name) return;
        await api.post(`/api/companies/${company.id}/contacts`, form);
        setForm({ name: '', email: '', phone: '', role: '', is_primary: false, is_billing: false });
        setShowForm(false);
        reload();
    }

    async function toggleFlag(contact, flag) {
        await api.patch(`/api/contacts/${contact.id}`, { [flag]: !contact[flag] });
        reload();
    }

    return (
        <div className="bg-white rounded-lg border border-border p-4 mb-6">
            <div className="flex items-center justify-between mb-3">
                <h2 className="text-sm font-semibold text-sage">Contacts</h2>
                <button onClick={() => setShowForm(!showForm)} className="text-sm font-medium text-brass">
                    {showForm ? 'Cancel' : '+ Add contact'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="grid grid-cols-2 gap-2 mb-4">
                    <input required placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                    <input placeholder="Role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                    <input type="email" placeholder="Email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                    <input placeholder="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_primary} onChange={(e) => setForm({ ...form, is_primary: e.target.checked })} />
                        Primary contact
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_billing} onChange={(e) => setForm({ ...form, is_billing: e.target.checked })} />
                        Billing contact
                    </label>
                    <button type="submit" className="col-span-2 bg-pine text-white text-sm font-medium px-3 py-1.5 rounded">Save contact</button>
                </form>
            )}

            {company.contacts.length === 0 ? (
                <EmptyState text="No contacts yet." />
            ) : (
                <ul className="text-sm divide-y divide-border">
                    {company.contacts.map((contact) => (
                        <li key={contact.id} className="py-2 flex items-center justify-between">
                            <div>
                                <div className="font-medium">
                                    {contact.name}{contact.role ? ` · ${contact.role}` : ''}
                                </div>
                                <div className="text-sage">{contact.email}</div>
                            </div>
                            <div className="flex items-center gap-2">
                                <ContactRoleBadges contact={contact} />
                                <button onClick={() => toggleFlag(contact, 'is_primary')} className="text-xs text-sage hover:text-ink">
                                    {contact.is_primary ? 'Unset primary' : 'Make primary'}
                                </button>
                                <button onClick={() => toggleFlag(contact, 'is_billing')} className="text-xs text-sage hover:text-ink">
                                    {contact.is_billing ? 'Unset billing' : 'Make billing'}
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function TaskRow({ task }) {
    const [status, setStatus] = useState(task.status);

    async function cycleStatus() {
        const order = ['todo', 'in_progress', 'done'];
        const next = order[(order.indexOf(status) + 1) % order.length];
        setStatus(next);
        await api.patch(`/api/tasks/${task.id}`, { status: next });
    }

    return (
        <li className="py-1.5 flex items-center justify-between text-sm">
            <span>{task.title}</span>
            <button onClick={cycleStatus}>
                <TaskStatusBadge task={{ ...task, status }} />
            </button>
        </li>
    );
}

function ProjectsCard({ company }) {
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState({ name: '', description: '' });
    const [taskInputs, setTaskInputs] = useState({});

    async function submit(e) {
        e.preventDefault();
        if (!form.name) return;
        await api.post(`/api/companies/${company.id}/projects`, form);
        setForm({ name: '', description: '' });
        setShowForm(false);
        reload();
    }

    async function addTask(projectId) {
        const title = taskInputs[projectId];
        if (!title) return;
        await api.post(`/api/projects/${projectId}/tasks`, { title });
        setTaskInputs({ ...taskInputs, [projectId]: '' });
        reload();
    }

    return (
        <div className="bg-white rounded-lg border border-border p-4 mb-6">
            <div className="flex items-center justify-between mb-3">
                <h2 className="text-sm font-semibold text-sage">Projects</h2>
                <button onClick={() => setShowForm(!showForm)} className="text-sm font-medium text-brass">
                    {showForm ? 'Cancel' : '+ Add project'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="flex gap-2 mb-4">
                    <input required placeholder="Project name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="border border-border rounded px-3 py-2 text-sm flex-1" />
                    <button type="submit" className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded">Save</button>
                </form>
            )}

            {company.projects.length === 0 ? (
                <EmptyState text="No projects yet." />
            ) : (
                <div className="divide-y divide-border">
                    {company.projects.map((project) => (
                        <div key={project.id} className="py-3">
                            <div className="flex items-center justify-between mb-2">
                                <Link href={`/projects/${project.id}`} className="text-sm font-medium hover:underline">
                                    {project.name}
                                </Link>
                                <ProjectStatusBadge project={project} />
                            </div>
                            <ul className="pl-2">
                                {project.tasks.map((task) => (
                                    <TaskRow key={task.id} task={task} />
                                ))}
                            </ul>
                            <div className="flex gap-2 mt-2">
                                <input
                                    placeholder="New task"
                                    value={taskInputs[project.id] || ''}
                                    onChange={(e) => setTaskInputs({ ...taskInputs, [project.id]: e.target.value })}
                                    className="border border-border rounded px-2 py-1 text-xs flex-1"
                                />
                                <button onClick={() => addTask(project.id)} className="text-xs font-medium text-pine">Add</button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function InvoicesCard({ company }) {
    return (
        <div className="bg-white rounded-lg border border-border p-4 mb-6">
            <h2 className="text-sm font-semibold text-sage mb-3">Invoices</h2>
            {company.invoices.length === 0 ? (
                <EmptyState text="No invoices yet." />
            ) : (
                <ul className="text-sm divide-y divide-border">
                    {company.invoices.map((invoice) => (
                        <li key={invoice.id} className="py-2 flex items-center justify-between">
                            <Link href={`/invoices/${invoice.id}`} className="hover:underline">
                                {formatDate(invoice.issued_on)}
                            </Link>
                            <div className="flex items-center gap-3">
                                <span className="font-mono">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</span>
                                <InvoiceStatusBadge invoice={invoice} />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function ProposalsCard({ company }) {
    return (
        <div className="bg-white rounded-lg border border-border p-4">
            <h2 className="text-sm font-semibold text-sage mb-3">Proposals</h2>
            {company.proposals.length === 0 ? (
                <EmptyState text="No proposals yet." />
            ) : (
                <ul className="text-sm divide-y divide-border">
                    {company.proposals.map((proposal) => (
                        <li key={proposal.id} className="py-2 flex items-center justify-between">
                            <span>{proposal.title}</span>
                            <div className="flex items-center gap-3">
                                {proposal.estimate_amount && (
                                    <span className="font-mono">{formatCurrency(proposal.estimate_amount)}</span>
                                )}
                                <ProposalStatusBadge proposal={proposal} />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function ClientsShow({ company }) {
    return (
        <AppLayout>
            <Head title={company.name} />
            <div className="mb-1">
                <Link href="/clients" className="text-sm text-sage hover:underline inline-flex items-center gap-1">
                    <ArrowLeft size={14} /> Clients
                </Link>
            </div>
            <DetailsCard company={company} />

            <ContactsCard company={company} />
            <ProjectsCard company={company} />
            <InvoicesCard company={company} />
            <ProposalsCard company={company} />
        </AppLayout>
    );
}

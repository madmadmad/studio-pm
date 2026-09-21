import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import { InvoiceStatusBadge, ProposalStatusBadge, ProjectStatusBadge, TaskStatusBadge, CompanyStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';

function reload() {
    router.reload({ only: ['company'] });
}

function formatAddress(company) {
    const cityStateZip = [company.city, [company.state, company.postal_code].filter(Boolean).join(' ')]
        .filter(Boolean)
        .join(', ');
    return [company.address_line1, cityStateZip].filter(Boolean).join(', ');
}

function DetailsCard({ company }) {
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({
        name: company.name,
        phone: company.phone ?? '',
        address_line1: company.address_line1 ?? '',
        city: company.city ?? '',
        state: company.state ?? '',
        postal_code: company.postal_code ?? '',
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
        const address = formatAddress(company);
        return (
            <div className="mb-6">
                <div className="flex items-center justify-between mb-1">
                    <h1 className="font-display text-2xl font-semibold">{company.name}</h1>
                    <div className="flex items-center gap-3">
                        <CompanyStatusBadge company={company} />
                        <Button variant="link" onClick={() => setEditing(true)}>Edit</Button>
                    </div>
                </div>
                <p className="text-sm text-shadow-grey">
                    {company.phone}{address ? `${company.phone ? ' · ' : ''}${address}` : ''}
                </p>
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="card card-padded mb-6 grid grid-cols-2 gap-3">
            <input required placeholder="Client or company name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="field col-span-2" />
            <input placeholder="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="field" />
            <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="field">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <input placeholder="Street address" value={form.address_line1} onChange={(e) => setForm({ ...form, address_line1: e.target.value })} className="field col-span-2" />
            <div className="col-span-2 grid grid-cols-[2fr_1fr_1fr] gap-3">
                <input placeholder="City" value={form.city} onChange={(e) => setForm({ ...form, city: e.target.value })} className="field" />
                <input placeholder="State" value={form.state} onChange={(e) => setForm({ ...form, state: e.target.value })} className="field" />
                <input placeholder="Zip" value={form.postal_code} onChange={(e) => setForm({ ...form, postal_code: e.target.value })} className="field" />
            </div>
            <div className="flex gap-2 col-span-2 justify-end">
                <Button type="button" variant="secondary" onClick={() => setEditing(false)}>Cancel</Button>
                <Button type="submit" variant="confirm" disabled={saving}>Save</Button>
            </div>
        </form>
    );
}

function ContactRoleBadges({ contact }) {
    return (
        <>
            {contact.is_primary && <Badge tone="fern" label="Primary" />}
            {contact.is_billing && <Badge tone="watermelon" label="Billing" />}
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

    async function inviteToPortal(contact) {
        if (!contact.email) {
            alert('Add an email address for this contact before inviting them to the portal.');
            return;
        }
        if (!confirm(`Invite ${contact.name} to the client portal? They'll get an email with a sign-in link.`)) return;
        try {
            await api.post(`/api/contacts/${contact.id}/portal-invite`);
            reload();
        } catch (err) {
            alert(err.message || 'Could not send this invite.');
        }
    }

    return (
        <div className="card card-padded mb-6">
            <div className="flex items-center justify-between mb-3">
                <h2 className="text-sm font-semibold text-shadow-grey">Contacts</h2>
                <Button variant="link-accent" onClick={() => setShowForm(!showForm)}>
                    {showForm ? 'Cancel' : '+ Add contact'}
                </Button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="grid grid-cols-2 gap-2 mb-4">
                    <input required placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="field" />
                    <input placeholder="Role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} className="field" />
                    <input type="email" placeholder="Email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="field" />
                    <input placeholder="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="field" />
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_primary} onChange={(e) => setForm({ ...form, is_primary: e.target.checked })} />
                        Primary contact
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_billing} onChange={(e) => setForm({ ...form, is_billing: e.target.checked })} />
                        Billing contact
                    </label>
                    <Button type="submit" variant="confirm" className="col-span-2">Save contact</Button>
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
                                <div className="text-shadow-grey">{contact.email}</div>
                            </div>
                            <div className="flex items-center gap-2">
                                <ContactRoleBadges contact={contact} />
                                <button onClick={() => toggleFlag(contact, 'is_primary')} className="text-xs text-shadow-grey hover:text-gunmetal">
                                    {contact.is_primary ? 'Unset primary' : 'Make primary'}
                                </button>
                                <button onClick={() => toggleFlag(contact, 'is_billing')} className="text-xs text-shadow-grey hover:text-gunmetal">
                                    {contact.is_billing ? 'Unset billing' : 'Make billing'}
                                </button>
                                {contact.has_portal_access ? (
                                    <Badge tone="sage" label="Portal access" />
                                ) : (
                                    <button onClick={() => inviteToPortal(contact)} className="text-xs text-watermelon hover:underline">
                                        Invite to portal
                                    </button>
                                )}
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
        <div className="card card-padded mb-6">
            <div className="flex items-center justify-between mb-3">
                <h2 className="text-sm font-semibold text-shadow-grey">Projects</h2>
                <Button variant="link-accent" onClick={() => setShowForm(!showForm)}>
                    {showForm ? 'Cancel' : '+ Add project'}
                </Button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="flex gap-2 mb-4">
                    <input required placeholder="Project name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="field flex-1" />
                    <Button type="submit" variant="confirm">Save</Button>
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
                                <button onClick={() => addTask(project.id)} className="text-xs font-medium text-fern">Add</button>
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
        <div className="card card-padded mb-6">
            <h2 className="text-sm font-semibold text-shadow-grey mb-3">Invoices</h2>
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
                                <span className="tabular-nums">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</span>
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
        <div className="card card-padded">
            <h2 className="text-sm font-semibold text-shadow-grey mb-3">Proposals</h2>
            {company.proposals.length === 0 ? (
                <EmptyState text="No proposals yet." />
            ) : (
                <ul className="text-sm divide-y divide-border">
                    {company.proposals.map((proposal) => (
                        <li key={proposal.id} className="py-2 flex items-center justify-between">
                            <span>{proposal.title}</span>
                            <div className="flex items-center gap-3">
                                {proposal.estimate_amount && (
                                    <span className="tabular-nums">{formatCurrency(proposal.estimate_amount)}</span>
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
                <Link href="/clients" className="text-sm text-shadow-grey hover:underline inline-flex items-center gap-1">
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

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { InvoiceStatusBadge, ProposalStatusBadge, ProjectStatusBadge, TaskStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';

function reload() {
    router.reload({ only: ['company'] });
}

function ContactsCard({ company }) {
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState({ name: '', email: '', phone: '', role: '' });

    async function submit(e) {
        e.preventDefault();
        if (!form.name) return;
        await api.post(`/api/companies/${company.id}/contacts`, form);
        setForm({ name: '', email: '', phone: '', role: '' });
        setShowForm(false);
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
                    <button type="submit" className="col-span-2 bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">Save contact</button>
                </form>
            )}

            {company.contacts.length === 0 ? (
                <EmptyState text="No contacts yet." />
            ) : (
                <ul className="text-sm divide-y divide-border">
                    {company.contacts.map((contact) => (
                        <li key={contact.id} className="py-2 flex justify-between">
                            <span className="font-medium">{contact.name}{contact.role ? ` · ${contact.role}` : ''}</span>
                            <span className="text-sage">{contact.email}</span>
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
                    <button type="submit" className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">Save</button>
                </form>
            )}

            {company.projects.length === 0 ? (
                <EmptyState text="No projects yet." />
            ) : (
                <div className="divide-y divide-border">
                    {company.projects.map((project) => (
                        <div key={project.id} className="py-3">
                            <div className="flex items-center justify-between mb-2">
                                <span className="text-sm font-medium">{project.name}</span>
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
                                <button onClick={() => addTask(project.id)} className="text-xs font-medium text-brass">Add</button>
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
                <Link href="/clients" className="text-sm text-sage hover:underline">&larr; Clients</Link>
            </div>
            <h1 className="text-2xl font-semibold mb-1">{company.name}</h1>
            <p className="text-sm text-sage mb-6">{company.email}{company.phone ? ` · ${company.phone}` : ''}</p>

            <ContactsCard company={company} />
            <ProjectsCard company={company} />
            <InvoicesCard company={company} />
            <ProposalsCard company={company} />
        </AppLayout>
    );
}

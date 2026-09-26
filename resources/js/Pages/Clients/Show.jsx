import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import { InvoiceStatusBadge, ProposalStatusBadge, ProjectStatusBadge, TaskStatusBadge, CompanyStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceTotal } from '../../lib/format';
import { CLIENT_PAYMENT_TERMS, paymentTermsLabel } from '../../lib/paymentTerms';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import BackLink from '../../Components/BackLink';

function reload() {
    router.reload({ only: ['company'] });
}

function formatAddress(company) {
    const cityStateZip = [company.city, [company.state, company.postal_code].filter(Boolean).join(' ')]
        .filter(Boolean)
        .join(', ');
    return [company.address_line1, cityStateZip].filter(Boolean).join(', ');
}

function DetailsCard({ company, firmDefaultTerms }) {
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
        default_payment_terms: company.default_payment_terms ?? '',
        reminders_enabled: company.reminders_enabled === null ? '' : company.reminders_enabled ? '1' : '0',
    });

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        try {
            await api.patch(`/api/companies/${company.id}`, {
                ...form,
                // The select's own values are plain strings ('', '1', '0')
                // -- reminders_enabled itself is a real nullable boolean on
                // the server, unlike default_payment_terms' '' sentinel.
                reminders_enabled: form.reminders_enabled === '' ? null : form.reminders_enabled === '1',
            });
            setEditing(false);
            reload();
        } finally {
            setSaving(false);
        }
    }

    if (!editing) {
        const address = formatAddress(company);
        return (
            <PageHeader
                title={company.name}
                actions={
                    <>
                        <CompanyStatusBadge company={company} />
                        <Button variant="link" onClick={() => setEditing(true)}>Edit</Button>
                    </>
                }
                subtitle={[
                    <>{company.phone}{address ? `${company.phone ? ' · ' : ''}${address}` : ''}</>,
                    <>Default payment terms: {company.default_payment_terms ? paymentTermsLabel(company.default_payment_terms) : `${paymentTermsLabel(company.effective_payment_terms)} (firm default)`}</>,
                    <>
                        Automatic reminders: {company.reminders_enabled === null
                            ? `${company.effective_reminders_enabled ? 'On' : 'Off'} (app default)`
                            : (company.reminders_enabled ? 'On' : 'Off')}
                    </>,
                ]}
            />
        );
    }

    return (
        <form onSubmit={submit} className="card card--padded form-grid page-section">
            <input required placeholder="Client or company name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="input form-grid__full" />
            <input placeholder="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="input" />
            <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })} className="input">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <div className="form-grid__full">
                <label className="label">Default payment terms</label>
                <select
                    value={form.default_payment_terms}
                    onChange={(e) => setForm({ ...form, default_payment_terms: e.target.value })}
                    className="input"
                >
                    <option value="">Firm default ({paymentTermsLabel(firmDefaultTerms)})</option>
                    {CLIENT_PAYMENT_TERMS.map((t) => (
                        <option key={t.value} value={t.value}>{t.label}</option>
                    ))}
                </select>
            </div>
            <div className="form-grid__full">
                <label className="label">Automatic reminders</label>
                <select
                    value={form.reminders_enabled}
                    onChange={(e) => setForm({ ...form, reminders_enabled: e.target.value })}
                    className="input"
                >
                    <option value="">Use app default</option>
                    <option value="1">On</option>
                    <option value="0">Off</option>
                </select>
            </div>
            <input placeholder="Street address" value={form.address_line1} onChange={(e) => setForm({ ...form, address_line1: e.target.value })} className="input form-grid__full" />
            <div className="form-grid__full address-fields">
                <input placeholder="City" value={form.city} onChange={(e) => setForm({ ...form, city: e.target.value })} className="input" />
                <input placeholder="State" value={form.state} onChange={(e) => setForm({ ...form, state: e.target.value })} className="input" />
                <input placeholder="Zip" value={form.postal_code} onChange={(e) => setForm({ ...form, postal_code: e.target.value })} className="input" />
            </div>
            <div className="form-actions form-grid__full">
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

    async function deleteContact(contact) {
        const warning = contact.has_portal_access
            ? ` This will also revoke their client portal access.`
            : '';
        if (!confirm(`Delete ${contact.name}?${warning}`)) return;
        try {
            await api.delete(`/api/contacts/${contact.id}`);
            reload();
        } catch (err) {
            alert(err.message || 'Could not delete this contact.');
        }
    }

    return (
        <div className="card card--padded page-section">
            <div className="card__header">
                <h2 className="card__title">Contacts</h2>
                <Button variant="link-accent" onClick={() => setShowForm(!showForm)}>
                    {showForm ? 'Cancel' : '+ Add contact'}
                </Button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="form-grid form-grid--tight card__section">
                    <input required placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="input" />
                    <input placeholder="Role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} className="input" />
                    <input type="email" placeholder="Email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="input" />
                    <input placeholder="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="input" />
                    <label className="choice">
                        <input type="checkbox" checked={form.is_primary} onChange={(e) => setForm({ ...form, is_primary: e.target.checked })} />
                        Primary contact
                    </label>
                    <label className="choice">
                        <input type="checkbox" checked={form.is_billing} onChange={(e) => setForm({ ...form, is_billing: e.target.checked })} />
                        Billing contact
                    </label>
                    <Button type="submit" variant="confirm" className="form-grid__full">Save contact</Button>
                </form>
            )}

            {company.contacts.length === 0 ? (
                <EmptyState text="No contacts yet." />
            ) : (
                <ul className="detail-list">
                    {company.contacts.map((contact) => (
                        <li key={contact.id} className="detail-list__item detail-list__item--split">
                            <div>
                                <div className="detail-list__title">
                                    {contact.name}{contact.role ? ` · ${contact.role}` : ''}
                                </div>
                                <div className="detail-list__meta">{contact.email}</div>
                            </div>
                            <div className="detail-list__aside detail-list__aside--tight">
                                <ContactRoleBadges contact={contact} />
                                <button onClick={() => toggleFlag(contact, 'is_primary')} className="text-action text-action--xs">
                                    {contact.is_primary ? 'Unset primary' : 'Make primary'}
                                </button>
                                <button onClick={() => toggleFlag(contact, 'is_billing')} className="text-action text-action--xs">
                                    {contact.is_billing ? 'Unset billing' : 'Make billing'}
                                </button>
                                {contact.has_portal_access ? (
                                    <Badge tone="sage" label="Portal access" />
                                ) : (
                                    <button onClick={() => inviteToPortal(contact)} className="link-btn link-btn--accent link-btn--xs">
                                        Invite to portal
                                    </button>
                                )}
                                <button onClick={() => deleteContact(contact)} className="link-btn link-btn--accent link-btn--xs">
                                    Delete
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
        <li className="client-detail__task">
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
        <div className="card card--padded page-section">
            <div className="card__header">
                <h2 className="card__title">Projects</h2>
                <Button variant="link-accent" onClick={() => setShowForm(!showForm)}>
                    {showForm ? 'Cancel' : '+ Add project'}
                </Button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="inline-form card__section">
                    <input required placeholder="Project name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="input inline-form__grow" />
                    <Button type="submit" variant="confirm">Save</Button>
                </form>
            )}

            {company.projects.length === 0 ? (
                <EmptyState text="No projects yet." />
            ) : (
                <div>
                    {company.projects.map((project) => (
                        <div key={project.id} className="client-detail__project">
                            <div className="client-detail__project-header">
                                <Link href={`/projects/${project.id}`} className="link client-detail__project-name">
                                    {project.name}
                                </Link>
                                <ProjectStatusBadge project={project} />
                            </div>
                            <ul className="client-detail__tasks">
                                {project.tasks.map((task) => (
                                    <TaskRow key={task.id} task={task} />
                                ))}
                            </ul>
                            <div className="client-detail__add-task">
                                <input
                                    placeholder="New task"
                                    value={taskInputs[project.id] || ''}
                                    onChange={(e) => setTaskInputs({ ...taskInputs, [project.id]: e.target.value })}
                                    className="client-detail__task-input"
                                />
                                <button onClick={() => addTask(project.id)} className="client-detail__task-submit">Add</button>
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
        <div className="card card--padded page-section">
            <h2 className="section-heading">Invoices</h2>
            {company.invoices.length === 0 ? (
                <EmptyState text="No invoices yet." />
            ) : (
                <ul className="detail-list">
                    {company.invoices.map((invoice) => (
                        <li key={invoice.id} className="detail-list__item detail-list__item--split">
                            <Link href={`/invoices/${invoice.id}`} className="link">
                                {formatDate(invoice.issued_on)}
                            </Link>
                            <div className="detail-list__aside">
                                <span className="detail-list__amount">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</span>
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
        <div className="card card--padded">
            <h2 className="section-heading">Proposals</h2>
            {company.proposals.length === 0 ? (
                <EmptyState text="No proposals yet." />
            ) : (
                <ul className="detail-list">
                    {company.proposals.map((proposal) => (
                        <li key={proposal.id} className="detail-list__item detail-list__item--split">
                            <span>{proposal.title}</span>
                            <div className="detail-list__aside">
                                {proposal.estimate_amount && (
                                    <span className="detail-list__amount">{formatCurrency(proposal.estimate_amount)}</span>
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

export default function ClientsShow({ company, firmPaymentTerms }) {
    return (
        <AppLayout>
            <Head title={company.name} />
            <BackLink href="/clients" label="Clients" />
            <DetailsCard company={company} firmDefaultTerms={firmPaymentTerms} />

            <ContactsCard company={company} />
            <ProjectsCard company={company} />
            <InvoicesCard company={company} />
            <ProposalsCard company={company} />
        </AppLayout>
    );
}

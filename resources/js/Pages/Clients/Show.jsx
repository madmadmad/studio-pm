import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import { InvoiceStatusBadge, ProposalStatusBadge, CompanyStatusBadge } from '../../Components/StatusBadges';
import ProjectsTable from '../../Components/ProjectsTable';
import { formatCurrency, formatDate, invoiceTotal } from '../../lib/format';
import { CLIENT_PAYMENT_TERMS, paymentTermsLabel } from '../../lib/paymentTerms';
import { api } from '../../lib/api';
import { visitRow } from '../../lib/rowLink';
import PageHeader from '../../Components/PageHeader';
import Avatar from '../../Components/Avatar';
import ActionMenu from '../../Components/ActionMenu';
import Drawer from '../../Components/Drawer';
import NewInvoiceDrawer from '../../Components/NewInvoiceDrawer';
import ProposalEditor from '../../Components/ProposalEditor';
import Toggle from '../../Components/Toggle';
import AutoResizeTextarea from '../../Components/AutoResizeTextarea';
import { GearSix, Plus } from '@phosphor-icons/react';
import BackLink from '../../Components/BackLink';

function reload() {
    router.reload({ only: ['company'] });
}

// A section's heading row: its title, plus -- when the section can add
// something -- the large + icon the project page's tabs use.
function SectionHeader({ title, addLabel, onAdd }) {
    return (
        <div className="card__header">
            <h2 className="card__title">{title}</h2>
            {onAdd && (
                <button onClick={onAdd} title={addLabel} aria-label={addLabel} className="icon-btn icon-btn--secondary icon-btn--lg">
                    <Plus />
                </button>
            )}
        </div>
    );
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
        const cityStateZip = [company.city, [company.state, company.postal_code].filter(Boolean).join(' ')]
            .filter(Boolean)
            .join(', ');
        return (
            <>
                <PageHeader
                    title={company.name}
                    actions={
                        <>
                            <CompanyStatusBadge company={company} />
                            <button onClick={() => setEditing(true)} title="Edit client" aria-label="Edit client" className="icon-btn icon-btn--secondary icon-btn--lg">
                                <GearSix />
                            </button>
                        </>
                    }
                />
                <div className="field-grid page-section">
                    <div>
                        <div className="section-label section-label--ruled">Address</div>
                        {company.address_line1 || cityStateZip ? (
                            <div className="field-grid__value">
                                {company.address_line1 && <div>{company.address_line1}</div>}
                                {cityStateZip && <div>{cityStateZip}</div>}
                            </div>
                        ) : <div className="field-grid__empty">—</div>}
                    </div>
                    <div>
                        <div className="section-label section-label--ruled">Phone</div>
                        {company.phone
                            ? <div className="field-grid__value">{company.phone}</div>
                            : <div className="field-grid__empty">—</div>}
                    </div>
                    <div>
                        <div className="section-label section-label--ruled">Payment terms</div>
                        <div className="field-grid__value">
                            {paymentTermsLabel(company.effective_payment_terms)}
                            {!company.default_payment_terms && <span className="field-grid__note"> (firm default)</span>}
                        </div>
                    </div>
                    <div>
                        <div className="section-label section-label--ruled">Automatic reminders</div>
                        <div className="field-grid__value">
                            {company.effective_reminders_enabled ? 'On' : 'Off'}
                            {company.reminders_enabled === null && <span className="field-grid__note"> (app default)</span>}
                        </div>
                    </div>
                </div>
            </>
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

const EMPTY_CONTACT = { name: '', role: '', email: '', phone: '', is_primary: false, is_billing: false };

// Add or edit a contact: `contact` is the one being edited, or null to add
// a new one to `company`. `onSaved` gets the saved contact from the server.
function ContactDrawer({ company, contact, onSaved, onClose }) {
    const [form, setForm] = useState(() => (contact
        ? {
            name: contact.name,
            role: contact.role ?? '',
            email: contact.email ?? '',
            phone: contact.phone ?? '',
            is_primary: contact.is_primary,
            is_billing: contact.is_billing,
        }
        : EMPTY_CONTACT));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function field(name) {
        return { value: form[name], onChange: (e) => setForm({ ...form, [name]: e.target.value }) };
    }

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setError('');
        try {
            const saved = contact
                ? await api.patch(`/api/contacts/${contact.id}`, form)
                : await api.post(`/api/companies/${company.id}/contacts`, form);
            onSaved(saved);
            onClose();
        } catch (err) {
            setError(err.message || 'Could not save this contact.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">{contact ? 'Edit contact' : 'New contact'}</h2>
            <form onSubmit={save}>
                <div className="form-grid drawer__section drawer__section--divided">
                    <div>
                        <div className="section-label section-label--tight">Name</div>
                        <input required autoFocus {...field('name')} className="input input--xs" />
                    </div>
                    <div>
                        <div className="section-label section-label--tight">Role</div>
                        <input placeholder="e.g. Marketing Director" {...field('role')} className="input input--xs" />
                    </div>
                    <div>
                        <div className="section-label section-label--tight">Email</div>
                        <input type="email" {...field('email')} className="input input--xs" />
                    </div>
                    <div>
                        <div className="section-label section-label--tight">Phone</div>
                        <input type="tel" {...field('phone')} className="input input--xs" />
                    </div>
                </div>

                <div className="drawer__section form-stack">
                    <div>
                        <Toggle
                            checked={form.is_primary}
                            onChange={(is_primary) => setForm({ ...form, is_primary })}
                            label="Primary contact"
                        />
                        <div className="form-hint form-hint--attached">One per client. Turning this on moves it from the current primary contact.</div>
                    </div>
                    <div className="form-stack__break">
                        <Toggle
                            checked={form.is_billing}
                            onChange={(is_billing) => setForm({ ...form, is_billing })}
                            label="Billing contact"
                        />
                        <div className="form-hint form-hint--attached">A client can have more than one.</div>
                    </div>
                </div>

                {error && <div className="form-message form-message--error drawer__section">{error}</div>}

                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>
                        {contact ? 'Save changes' : 'Add contact'}
                    </Button>
                </div>
            </form>
        </Drawer>
    );
}

function ContactsCard({ company }) {
    // null: closed; 'new': adding; a contact: editing it.
    const [editing, setEditing] = useState(null);
    // A local copy so a save shows on the cards immediately, without
    // waiting on the page reload; re-synced whenever that reload lands.
    const [contacts, setContacts] = useState(company.contacts);
    useEffect(() => setContacts(company.contacts), [company.contacts]);

    // Puts the server's copy of a saved contact into the list (adding it if
    // new) and mirrors the server's one-primary-per-client rule, then
    // reloads in the background to pick up anything else that changed.
    function applySaved(saved) {
        setContacts((current) => {
            const updated = current.map((c) => {
                if (c.id === saved.id) return saved;
                return saved.is_primary ? { ...c, is_primary: false } : c;
            });
            return current.some((c) => c.id === saved.id) ? updated : [...updated, saved];
        });
        reload();
    }

    async function toggleFlag(contact, flag) {
        try {
            applySaved(await api.patch(`/api/contacts/${contact.id}`, { [flag]: !contact[flag] }));
        } catch (err) {
            alert(err.message || 'Could not update this contact.');
        }
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
            setContacts((current) => current.filter((c) => c.id !== contact.id));
            reload();
        } catch (err) {
            alert(err.message || 'Could not delete this contact.');
        }
    }

    return (
        <div className="page-section">
            <SectionHeader title="Contacts" addLabel="Add contact" onAdd={() => setEditing('new')} />

            {contacts.length === 0 ? (
                <EmptyState text="No contacts yet." />
            ) : (
                <div className="contact-grid">
                    {contacts.map((contact) => (
                        <div key={contact.id} className="card card--padded contact-card">
                            <div className="contact-card__header">
                                <Avatar name={contact.name} avatarUrl={contact.avatar_url} id={contact.id} size={40} />
                                <div className="contact-card__identity">
                                    <div className="contact-card__name">{contact.name}</div>
                                    {contact.role && <div className="contact-card__role">{contact.role}</div>}
                                </div>
                                <ActionMenu
                                    label={`Settings for ${contact.name}`}
                                    icon={<GearSix />}
                                    items={[
                                        { label: 'Edit contact', onSelect: () => setEditing(contact) },
                                        { label: contact.is_primary ? 'Unset primary' : 'Make primary', onSelect: () => toggleFlag(contact, 'is_primary') },
                                        { label: contact.is_billing ? 'Unset billing' : 'Make billing', onSelect: () => toggleFlag(contact, 'is_billing') },
                                        !contact.has_portal_access && { label: 'Invite to portal', onSelect: () => inviteToPortal(contact) },
                                        { label: 'Delete contact', onSelect: () => deleteContact(contact), danger: true },
                                    ]}
                                />
                            </div>
                            {(contact.email || contact.phone) && (
                                <div className="contact-card__details">
                                    {contact.email && (
                                        <div>
                                            <a href={`mailto:${contact.email}`} className="link link--muted">{contact.email}</a>
                                        </div>
                                    )}
                                    {contact.phone && <div>{contact.phone}</div>}
                                </div>
                            )}
                            {(contact.is_primary || contact.is_billing || contact.has_portal_access) && (
                                <div className="contact-card__badges">
                                    <ContactRoleBadges contact={contact} />
                                    {contact.has_portal_access && <Badge tone="sage" label="Portal access" />}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {editing && (
                <ContactDrawer
                    key={editing === 'new' ? 'new' : editing.id}
                    company={company}
                    contact={editing === 'new' ? null : editing}
                    onSaved={applySaved}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

// New project for this client: name, the contact it's for (the client's
// primary contact by default, like the Projects page) and a description.
function NewProjectDrawer({ company, onClose }) {
    const primary = company.contacts.find((c) => c.is_primary);
    const [form, setForm] = useState({ name: '', contact_id: primary ? String(primary.id) : '', description: '' });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setError('');
        try {
            await api.post(`/api/companies/${company.id}/projects`, {
                name: form.name,
                contact_id: form.contact_id || null,
                description: form.description,
            });
            reload();
            onClose();
        } catch (err) {
            setError(err.message || 'Could not create this project.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">New project</h2>
            <form onSubmit={save}>
                <div className="drawer__section drawer__section--divided">
                    <div className="section-label section-label--tight">Name</div>
                    <input required autoFocus value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="input input--xs" />
                </div>
                <div className="drawer__section">
                    <div className="section-label section-label--tight">Contact</div>
                    <select value={form.contact_id} onChange={(e) => setForm({ ...form, contact_id: e.target.value })} className="input input--xs">
                        <option value="">No contact</option>
                        {company.contacts.map((c) => (
                            <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                    </select>
                </div>
                <div className="drawer__section">
                    <div className="section-label">Description</div>
                    <AutoResizeTextarea
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        placeholder="Add a description…"
                        className="input"
                    />
                </div>

                {error && <div className="form-message form-message--error drawer__section">{error}</div>}

                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>Create project</Button>
                </div>
            </form>
        </Drawer>
    );
}

function ProjectsCard({ company }) {
    const [creating, setCreating] = useState(false);

    return (
        <div className="page-section">
            <SectionHeader title="Projects" addLabel="New project" onAdd={() => setCreating(true)} />

            {company.projects.length === 0 ? (
                <EmptyState text="No projects yet." />
            ) : (
                <ProjectsTable projects={company.projects} showClient={false} onChange={reload} />
            )}

            {creating && <NewProjectDrawer company={company} onClose={() => setCreating(false)} />}
        </div>
    );
}

function InvoicesCard({ company }) {
    const [creating, setCreating] = useState(false);

    return (
        <div className="page-section">
            <SectionHeader title="Invoices" addLabel="New invoice" onAdd={() => setCreating(true)} />
            {company.invoices.length === 0 ? (
                <EmptyState text="No invoices yet." />
            ) : (
                <table className="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Project</th>
                            <th>Issued</th>
                            <th>Due</th>
                            <th>Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {company.invoices.map((invoice) => (
                            <tr key={invoice.id} onClick={(e) => visitRow(e, `/invoices/${invoice.id}`)} className="table__row--link">
                                <td className="table__cell--numeric table__cell--strong">
                                    <Link href={`/invoices/${invoice.id}`} className="link">{invoice.invoice_number}</Link>
                                </td>
                                <td className="table__cell--muted">{invoice.project?.name ?? '—'}</td>
                                <td className="table__cell--muted">{formatDate(invoice.issued_on)}</td>
                                <td className="table__cell--muted">{formatDate(invoice.due_on)}</td>
                                <td className="table__cell--numeric">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</td>
                                <td><InvoiceStatusBadge invoice={invoice} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {creating && (
                <NewInvoiceDrawer
                    company={company}
                    projects={company.projects}
                    onCreated={reload}
                    onClose={() => setCreating(false)}
                />
            )}
        </div>
    );
}

// New proposal for this client, in the wide drawer with the same editor
// the Proposals page uses. The client is fixed; the project is picked in
// the editor (one of this client's, or a new one).
function NewProposalDrawer({ company, services, onClose }) {
    const companies = [{
        id: company.id,
        name: company.name,
        contacts: company.contacts,
        projects: company.projects.map((p) => ({ id: p.id, name: p.name })),
    }];

    return (
        <Drawer size="wide" onClose={onClose}>
            <h2 className="drawer__title">New proposal</h2>
            <ProposalEditor
                proposal={null}
                companies={companies}
                services={services}
                presetCompanyId={company.id}
                presetProjectId={null}
                onSaved={() => {
                    reload();
                    onClose();
                }}
                onCancel={onClose}
            />
        </Drawer>
    );
}

function ProposalsCard({ company, services }) {
    const [creating, setCreating] = useState(false);

    return (
        <div className="page-section">
            <SectionHeader title="Proposals" addLabel="New proposal" onAdd={() => setCreating(true)} />
            {company.proposals.length === 0 ? (
                <EmptyState text="No proposals yet." />
            ) : (
                <table className="table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Project</th>
                            <th>Estimate</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {company.proposals.map((proposal) => (
                            <tr key={proposal.id} onClick={(e) => visitRow(e, `/proposals/${proposal.id}/edit`)} className="table__row--link">
                                <td className="table__cell--strong">
                                    <Link href={`/proposals/${proposal.id}/edit`} className="link">{proposal.title}</Link>
                                </td>
                                <td className="table__cell--muted">{proposal.project?.name ?? '—'}</td>
                                <td className="table__cell--numeric">
                                    {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                                </td>
                                <td><ProposalStatusBadge proposal={proposal} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {creating && <NewProposalDrawer company={company} services={services} onClose={() => setCreating(false)} />}
        </div>
    );
}

export default function ClientsShow({ company, firmPaymentTerms, services }) {
    return (
        <AppLayout>
            <Head title={company.name} />
            <BackLink href="/clients" label="Clients" />
            <DetailsCard company={company} firmDefaultTerms={firmPaymentTerms} />

            <ContactsCard company={company} />
            <ProjectsCard company={company} />
            <InvoicesCard company={company} />
            <ProposalsCard company={company} services={services} />
        </AppLayout>
    );
}

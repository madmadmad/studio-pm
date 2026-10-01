import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CaretRight, Check, CheckCircle, Copy, DownloadSimple, Eye, PaperPlaneTilt, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import InvoiceLineItems from '../../Components/InvoiceLineItems';
import EmptyState from '../../Components/EmptyState';
import { TaxRow, TaxToggle, useSalesTax } from '../../Components/InvoiceTax';
import InvoiceDateFields, { useInvoiceDateFields } from '../../Components/InvoiceDateFields';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { displayInvoiceStatus, formatCurrency, formatDate, invoiceSubtotal, invoiceTotal, monthInAppTimezone, todayInAppTimezone } from '../../lib/format';
import MetricCard from '../../Components/MetricCard';
import { calculateDueDate, todayLocal } from '../../lib/paymentTerms';
import { api } from '../../lib/api';
import { getTray, clearTray } from '../../lib/tray';
import { copyToClipboard } from '../../lib/clipboard';
import { visitRow } from '../../lib/rowLink';
import PageHeader from '../../Components/PageHeader';
import { useSendAfterCreate } from '../../Components/SendInvoiceModal';
import { useInvoiceDrawer } from '../../Components/InvoiceDrawer';
import { useListMotion } from '../../lib/listMotion';

// The list's filter pills, by the status each row's badge shows: drafts
// include ones with a send scheduled; outstanding is sent and unpaid,
// overdue or not.
const STATUS_FILTERS = [
    { value: 'all', label: 'All', statuses: null },
    { value: 'draft', label: 'Draft', statuses: ['draft', 'scheduled'] },
    { value: 'outstanding', label: 'Outstanding', statuses: ['sent', 'overdue'] },
    { value: 'overdue', label: 'Overdue', statuses: ['overdue'] },
    { value: 'paid', label: 'Paid', statuses: ['paid'] },
];

// The figures across the top: what's owed (sent and unpaid, overdue
// included), what's overdue, and this month's payments and sends -- each
// a count and an amount. Months are the firm's (Eastern).
function invoiceMetrics(invoices) {
    const thisMonth = todayInAppTimezone().slice(0, 7);
    const owed = (invoice) => invoiceTotal(invoice.items, invoice.surcharge, invoice.tax_rate);
    const figure = (list, amount = owed) => ({ count: list.length, amount: list.reduce((s, item) => s + amount(item), 0) });

    const unpaid = invoices.filter((i) => i.status === 'sent');
    const payments = invoices
        .flatMap((i) => i.payments || [])
        .filter((payment) => payment.paid_at && monthInAppTimezone(payment.paid_at) === thisMonth);

    return {
        outstanding: figure(unpaid),
        overdue: figure(unpaid.filter((i) => displayInvoiceStatus(i) === 'overdue')),
        paid: figure(payments, (payment) => parseFloat(payment.amount) || 0),
        sent: figure(invoices.filter((i) => i.sent_at && monthInAppTimezone(i.sent_at) === thisMonth)),
    };
}

function emptyDraft() {
    const issuedOn = todayLocal();
    return {
        company_id: '', contact_id: '', items: [{ description: '', amount: '' }], tax_rate: null, tax_name: null,
        issued_on: issuedOn, payment_terms: 'net_30', due_on: calculateDueDate(issuedOn, 'net_30'),
    };
}

export default function InvoicesIndex({ invoices: invoicesProp, companies }) {
    const [invoices, setInvoices] = useState(invoicesProp);
    const [showForm, setShowForm] = useState(false);
    const [draft, setDraft] = useState(emptyDraft());
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [copiedId, setCopiedId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);
    const [dueSort, setDueSort] = useState(null); // null | 'asc' | 'desc'
    const [search, setSearch] = useState('');
    const rowsRef = useListMotion();
    const [filter, setFilter] = useState('all');
    // Invoices open in the wide drawer, as on a project or client.
    const { openInvoice, drawer: invoiceDrawer } = useInvoiceDrawer(() => router.reload({ only: ['invoices'] }));

    // Keeps local state in sync whenever a router.reload() elsewhere in this
    // component brings in a fresh copy of the prop.
    useEffect(() => {
        setInvoices(invoicesProp);
    }, [invoicesProp]);

    async function copyLink(invoice) {
        const ok = await copyToClipboard(`${window.location.origin}/i/${invoice.public_token}`);
        if (!ok) {
            alert('Could not copy the link. Copy it manually instead.');
            return;
        }
        setCopiedId(invoice.id);
        setTimeout(() => setCopiedId((id) => (id === invoice.id ? null : id)), 1500);
    }

    function billingContactFor(companyId) {
        const company = companies.find((c) => String(c.id) === String(companyId));
        return company?.contacts?.find((c) => c.is_billing);
    }
    const contactsForCompany = companies.find((c) => String(c.id) === String(draft.company_id))?.contacts || [];

    const dateFields = useInvoiceDateFields(draft, (patch) => setDraft((current) => ({ ...current, ...patch })));

    function handleCompanyChange(value) {
        const billingContact = billingContactFor(value);
        setDraft({ ...draft, company_id: value, contact_id: billingContact ? String(billingContact.id) : '' });

        const company = companies.find((c) => String(c.id) === String(value));
        if (company) {
            dateFields.applyClientDefaultTerms(company.effective_payment_terms);
        }
    }

    useEffect(() => {
        if (window.location.search.includes('from_tray=1')) {
            const tray = getTray();
            if (tray.length > 0) {
                const companyIds = [...new Set(tray.map((t) => t.company_id))];
                const singleCompanyId = companyIds.length === 1 ? String(companyIds[0]) : '';
                const billingContact = singleCompanyId ? billingContactFor(singleCompanyId) : null;
                const company = singleCompanyId ? companies.find((c) => String(c.id) === singleCompanyId) : null;
                const issuedOn = todayLocal();
                const terms = company?.effective_payment_terms || 'net_30';
                setDraft({
                    company_id: singleCompanyId,
                    contact_id: billingContact ? String(billingContact.id) : '',
                    items: tray.map((t) => ({
                        description: t.description,
                        amount: String(t.amount.toFixed(2)),
                        time_entry_ids: [t.time_entry_id],
                    })),
                    tax_rate: null,
                    tax_name: null,
                    issued_on: issuedOn,
                    payment_terms: terms,
                    due_on: calculateDueDate(issuedOn, terms),
                });
                setShowForm(true);
            }
            window.history.replaceState(null, '', '/invoices');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const subtotal = invoiceSubtotal(draft.items);
    const total = invoiceTotal(draft.items, draft.surcharge, draft.tax_rate);
    const salesTax = useSalesTax();
    const metrics = invoiceMetrics(invoices);

    // Search matches the invoice number, client or project; the pills
    // filter by status. Same bar as the Projects and Clients lists.
    const query = search.trim().toLowerCase();
    const statuses = STATUS_FILTERS.find((f) => f.value === filter).statuses;
    const filteredInvoices = invoices.filter((invoice) => {
        if (statuses && !statuses.includes(displayInvoiceStatus(invoice))) return false;
        if (!query) return true;
        return String(invoice.invoice_number).includes(query)
            || invoice.company?.name.toLowerCase().includes(query)
            || invoice.project?.name.toLowerCase().includes(query);
    });

    const visibleInvoices = dueSort
        ? [...filteredInvoices].sort((a, b) => (dueSort === 'asc' ? 1 : -1) * (new Date(a.due_on) - new Date(b.due_on)))
        : filteredInvoices;

    function toggleDueSort() {
        setDueSort((current) => (current === 'asc' ? 'desc' : current === 'desc' ? null : 'asc'));
    }


    function openNewInvoice() {
        setDraft(emptyDraft());
        setError('');
        setShowForm(true);
    }

    // Send invoice: the dialog opens once the invoice is created; either
    // way it ends, the form closes (the invoice is already on the list).
    const { openFor: openSendDialog, modal: sendDialog } = useSendAfterCreate(() => {
        setShowForm(false);
        router.reload({ only: ['invoices'] });
    }, () => router.reload({ only: ['invoices'] }));

    async function saveInvoice(status) {
        if (!draft.company_id) {
            setError('Select a client first.');
            return;
        }
        const validItems = draft.items.filter((i) => i.description.trim() && parseFloat(i.amount) > 0);
        if (validItems.length === 0) {
            setError('Add at least one line item with a description and amount.');
            return;
        }

        setSaving(true);
        setError('');
        try {
            const invoice = await api.post(`/api/companies/${draft.company_id}/invoices`, {
                contact_id: draft.contact_id || null,
                tax: draft.tax_rate != null,
                issued_on: draft.issued_on,
                payment_terms: draft.payment_terms,
                due_on: draft.due_on,
                items: validItems,
            });
            clearTray();
            router.reload({ only: ['invoices'] });
            // Send goes through the Send Invoice dialog (method, timing, the
            // email itself) rather than straight out.
            if (status === 'sent') {
                await openSendDialog(invoice.id);
                return;
            }
            setShowForm(false);
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    // A draft row's Send icon opens the Send Invoice dialog, as sending does
    // everywhere else.
    const reloadInvoices = () => router.reload({ only: ['invoices'] });
    const { openFor: openRowSendDialog, modal: rowSendDialog } = useSendAfterCreate(reloadInvoices, reloadInvoices);

    function sendInvoice(invoice) {
        openRowSendDialog(invoice.id, { justCreated: false });
    }

    // Quick action for the common case (a check came in) -- anything else
    // (e.g. "other") is recorded from the invoice detail page instead.
    async function markPaid(invoice) {
        await api.post(`/api/invoices/${invoice.id}/mark-paid`, { method: 'check' });
        router.reload({ only: ['invoices'] });
    }

    async function deleteInvoice(invoice) {
        if (deletingId === invoice.id) return; // already in flight -- ignore a repeat click
        const amount = formatCurrency(invoiceTotal(invoice.items, invoice.surcharge, invoice.tax_rate));
        const warning = `Delete this ${amount} invoice to ${invoice.company?.name}? This can't be undone.`;
        if (!confirm(warning)) return;
        setDeletingId(invoice.id);
        try {
            await api.delete(`/api/invoices/${invoice.id}`);
            // Remove it from local state directly rather than waiting on a
            // router.reload() round-trip to reflect the change.
            setInvoices((current) => current.filter((i) => i.id !== invoice.id));
        } catch (err) {
            alert(err.message || 'Could not delete this invoice.');
        } finally {
            setDeletingId(null);
        }
    }

    return (
        <AppLayout>
            <Head title="Invoices" />
            <PageHeader
                title="Invoices"
                actions={<Button onClick={openNewInvoice}>New invoice</Button>}
            />

            <div className="metric-grid">
                <MetricCard label={`Outstanding (${metrics.outstanding.count})`} value={formatCurrency(metrics.outstanding.amount)} />
                <MetricCard label={`Paid this month (${metrics.paid.count})`} value={formatCurrency(metrics.paid.amount)} />
                <MetricCard label={`Sent this month (${metrics.sent.count})`} value={formatCurrency(metrics.sent.amount)} />
                {/* Red only when something's overdue. */}
                <MetricCard
                    label={`Overdue (${metrics.overdue.count})`}
                    value={formatCurrency(metrics.overdue.amount)}
                    tone={metrics.overdue.count > 0 ? 'primary' : null}
                />
            </div>

            {showForm && (
                <div className="card card--padded page-section invoice-form">
                    <div className="invoice-form__section invoice-form__parties">
                        <select
                            required
                            value={draft.company_id}
                            onChange={(e) => handleCompanyChange(e.target.value)}
                            className="input"
                        >
                            <option value="">Select client</option>
                            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        <select
                            value={draft.contact_id}
                            disabled={!draft.company_id}
                            onChange={(e) => setDraft({ ...draft, contact_id: e.target.value })}
                            className="input"
                        >
                            <option value="">
                                {draft.company_id ? 'Bill to (no specific contact)' : 'Select a client first'}
                            </option>
                            {contactsForCompany.map((contact) => (
                                <option key={contact.id} value={contact.id}>
                                    {contact.name}{contact.email ? ` (${contact.email})` : ''}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="form-panel">
                        <div className="section-label section-label--ruled">Dates &amp; terms</div>
                        <InvoiceDateFields values={draft} onChange={(patch) => setDraft((current) => ({ ...current, ...patch }))} />
                    </div>

                    <InvoiceLineItems items={draft.items} taxed={draft.tax_rate != null} onChange={(items) => setDraft((current) => ({ ...current, items }))} />

                    <div className="invoice-form__section totals">
                        <div className="totals__row totals__row--muted">
                            <span>Subtotal</span>
                            <span className="totals__value">{formatCurrency(subtotal)}</span>
                        </div>
                        <TaxRow items={draft.items} taxName={draft.tax_name} taxRate={draft.tax_rate} />
                        <div className="totals__row totals__row--strong">
                            <span>Total</span>
                            <span className="totals__value">{formatCurrency(total)}</span>
                        </div>
                    </div>

                    {error && <div className="form-message form-message--error form-message--spaced">{error}</div>}
                    {/* The tax toggle on the left, the form's buttons on the right. */}
                    <div className="invoice-form__footer">
                        <TaxToggle form={draft} salesTax={salesTax} onChange={(patch) => setDraft((current) => ({ ...current, ...patch }))} />
                        <div className="form-actions">
                            <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                            <Button type="button" variant="secondary" disabled={saving} onClick={() => saveInvoice('draft')}>Save as draft</Button>
                            <Button type="button" disabled={saving} onClick={() => saveInvoice('sent')}>Send invoice</Button>
                        </div>
                    </div>
                </div>
            )}

            {invoices.length > 0 && (
                <div className="filter-bar">
                    <input
                        type="search"
                        placeholder="Search invoices, clients or projects…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-label="Search invoices"
                        className="input filter-bar__search"
                    />
                    <div className="filter-bar__pills">
                        {STATUS_FILTERS.map((f) => (
                            <button
                                key={f.value}
                                onClick={() => setFilter(f.value)}
                                className={`filter-bar__pill${filter === f.value ? ' filter-bar__pill--active' : ''}`}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <div className="card card--flush">
                {invoices.length === 0 ? (
                    <EmptyState text="No invoices yet." />
                ) : visibleInvoices.length === 0 ? (
                    <EmptyState text={query ? `No invoices match "${search.trim()}".` : 'No invoices match this filter.'} />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Client</th>
                                <th>Issued</th>
                                <th>
                                    <button onClick={toggleDueSort} className="table__sort">
                                        Due
                                        {dueSort && <span className="table__sort-indicator">{dueSort === 'asc' ? '↑' : '↓'}</span>}
                                    </button>
                                </th>
                                <th>Total</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody ref={rowsRef}>
                            {visibleInvoices.map((invoice) => (
                                <tr key={invoice.id} onClick={(e) => visitRow(e, null, { onOpen: () => openInvoice(invoice.id) })} className="table__row--link">
                                    <td className="table__cell--numeric table__cell--muted">{invoice.invoice_number}</td>
                                    <td className="table__cell--strong">{invoice.company?.name}</td>
                                    <td className="table__cell--muted">{formatDate(invoice.issued_on)}</td>
                                    <td className="table__cell--muted">{formatDate(invoice.due_on)}</td>
                                    <td className="table__cell--numeric">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge, invoice.tax_rate))}</td>
                                    <td><InvoiceStatusBadge invoice={invoice} /></td>
                                    <td className="table__cell--end">
                                        <div className="table__actions">
                                            <a href={`/i/${invoice.public_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="icon-btn icon-btn--secondary">
                                                <Eye />
                                            </a>
                                            {invoice.status === 'draft' && (
                                                <button onClick={() => sendInvoice(invoice)} title="Send" className="icon-btn icon-btn--accent">
                                                    <PaperPlaneTilt />
                                                </button>
                                            )}
                                            {invoice.status !== 'draft' && (
                                                <button onClick={() => copyLink(invoice)} title={copiedId === invoice.id ? 'Copied!' : 'Copy link'} className="icon-btn icon-btn--secondary">
                                                    {copiedId === invoice.id ? <Check /> : <Copy />}
                                                </button>
                                            )}
                                            {invoice.status !== 'draft' && (
                                                <a href={`/invoices/${invoice.id}/pdf`} title="Download PDF" className="icon-btn icon-btn--secondary">
                                                    <DownloadSimple />
                                                </a>
                                            )}
                                            {invoice.status === 'sent' && (
                                                <button onClick={() => markPaid(invoice)} title="Mark paid (check)" className="icon-btn icon-btn--confirm">
                                                    <CheckCircle />
                                                </button>
                                            )}
                                            {invoice.status !== 'paid' && (
                                                <button
                                                    onClick={() => deleteInvoice(invoice)}
                                                    disabled={deletingId === invoice.id}
                                                    title="Delete"
                                                    className="icon-btn icon-btn--danger"
                                                >
                                                    <Trash />
                                                </button>
                                            )}
                                            {/* The keyboard way in; the row's own click does the same. */}
                                            <button onClick={() => openInvoice(invoice.id)} title="Open invoice" aria-label="Open invoice" className="row-action">
                                                <CaretRight size={14} weight="bold" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {invoiceDrawer}
            {sendDialog}
            {rowSendDialog}
        </AppLayout>
    );
}

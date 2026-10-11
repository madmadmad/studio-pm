import { Head, router } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { CaretRight, Check, CheckCircle, Copy, DownloadSimple, Eye, PaperPlaneTilt, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import InvoiceLineItems from '../../Components/InvoiceLineItems';
import EmptyState from '../../Components/EmptyState';
import { TaxRow, TaxToggle, useSalesTax } from '../../Components/InvoiceTax';
import { InvoiceCategoryBadge, InvoiceCategorySelect, PROJECT_WORK, RepeatIcon, useInvoiceCategories } from '../../Components/InvoiceCategory';
import InvoiceDateFields, { useInvoiceDateFields } from '../../Components/InvoiceDateFields';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceTotal } from '../../lib/format';
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
import TabToolbar from '../../Components/TabToolbar';
import LoadMore from '../../Components/LoadMore';
import MonthDivider from '../../Components/MonthDivider';

// The list's filter pills, by the status each row's badge shows: drafts
// include ones with a send scheduled; outstanding is sent and unpaid,
// overdue or not. The server does the filtering (App\Services\InvoiceList);
// All and Paid list a few months at a time, the rest everything -- so only
// those can be sorted by due date.
const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'outstanding', label: 'Outstanding' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'paid', label: 'Paid' },
];
const UNWINDOWED = ['draft', 'outstanding', 'overdue'];
const MONTHS_STEP = 3;
const SEARCH_DELAY_MS = 300;

// The list's props, which every reload after a change brings back fresh.
const LIST_PROPS = ['invoices', 'filters', 'hasEarlier', 'metrics', 'hasInvoices'];

// The filters as the page's address: defaults left out, so /invoices is
// the plain list.
function addressFor({ status, q, category, months }) {
    const params = {};
    if (status && status !== 'all') params.status = status;
    if (q) params.q = q;
    if (category && category !== 'all') params.category = category;
    if (months && months > MONTHS_STEP) params.months = months;
    return params;
}

// The 'YYYY-MM' an invoice was issued in ('' with no issue date).
function issuedMonth(invoice) {
    return invoice.issued_on ? String(invoice.issued_on).slice(0, 7) : '';
}

// Each month's figures for its divider, over the invoices listed (every
// category, as filtered): what was invoiced -- sent or paid -- and any
// drafts not yet sent, apart.
function totalsByMonth(invoices) {
    const months = {};
    for (const invoice of invoices) {
        const key = issuedMonth(invoice);
        months[key] ??= { count: 0, invoiced: 0, drafts: 0, draftCount: 0 };
        const amount = invoiceTotal(invoice.items, invoice.surcharge, invoice.tax_rate);
        if (invoice.status === 'draft') {
            months[key].drafts += amount;
            months[key].draftCount += 1;
        } else {
            months[key].invoiced += amount;
            months[key].count += 1;
        }
    }
    return months;
}

// The divider above a month's invoices, with what was invoiced in it.
function InvoiceMonth({ month, totals }) {
    return (
        <MonthDivider month={month} colSpan={7} emptyLabel="No issue date">
            {totals.count} invoiced &middot; <strong>{formatCurrency(totals.invoiced)}</strong>
            {totals.draftCount > 0 && <span> &middot; {formatCurrency(totals.drafts)} in {totals.draftCount === 1 ? 'a draft' : `${totals.draftCount} drafts`}</span>}
        </MonthDivider>
    );
}

function emptyDraft() {
    const issuedOn = todayLocal();
    return {
        company_id: '', contact_id: '', items: [{ description: '', amount: '' }], tax_rate: null, tax_name: null,
        issued_on: issuedOn, payment_terms: 'net_30', due_on: calculateDueDate(issuedOn, 'net_30'),
    };
}

export default function InvoicesIndex({ invoices: invoicesProp, filters, hasEarlier, metrics, hasInvoices, companies }) {
    const [invoices, setInvoices] = useState(invoicesProp);
    const [showForm, setShowForm] = useState(false);
    const [draft, setDraft] = useState(emptyDraft());
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [copiedId, setCopiedId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);
    const [dueSort, setDueSort] = useState(null); // null | 'asc' | 'desc'
    const [search, setSearch] = useState(filters.q ?? '');
    const [loading, setLoading] = useState(false);
    const rowsRef = useListMotion();
    const filter = filters.status;
    const categoryFilter = filters.category;
    const categories = useInvoiceCategories();
    // Invoices open in the wide drawer, as on a project or client.
    const { openInvoice, drawer: invoiceDrawer } = useInvoiceDrawer(() => reloadList());

    // A new view of the list: the filters into the address, and the list
    // (only) fetched for it. Keeps everything else on the page as it is.
    function showList(changes) {
        const next = { ...filters, ...changes };
        router.get('/invoices', addressFor(next), {
            only: LIST_PROPS,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    }

    // The same view, fresh -- after a save, send, payment or delete.
    function reloadList() {
        router.reload({ only: LIST_PROPS });
    }

    // Search as you type, a moment after the typing stops.
    const firstSearch = useRef(true);
    useEffect(() => {
        if (firstSearch.current) {
            firstSearch.current = false;
            return undefined;
        }
        const timer = setTimeout(() => showList({ q: search.trim(), months: MONTHS_STEP }), SEARCH_DELAY_MS);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

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

    // The server's already filtered them (status, category, search -- the
    // invoice number, client or project). Newest first by issue date, under
    // a divider per month -- unless they're sorted by due date, which runs
    // across months (only where the whole list is here to sort).
    const query = (filters.q ?? '').trim();
    const canSortByDue = UNWINDOWED.includes(filter);
    const sortByDue = canSortByDue ? dueSort : null;
    const visibleInvoices = sortByDue
        ? [...invoices].sort((a, b) => (sortByDue === 'asc' ? 1 : -1) * (new Date(a.due_on) - new Date(b.due_on)))
        : [...invoices].sort((a, b) => issuedMonth(b).localeCompare(issuedMonth(a))
            || String(b.issued_on).localeCompare(String(a.issued_on))
            || b.invoice_number - a.invoice_number);
    const monthTotals = sortByDue ? {} : totalsByMonth(visibleInvoices);

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
        reloadList();
    }, () => reloadList());

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
                category_id: draft.category_id || null,
                tax: draft.tax_rate != null,
                issued_on: draft.issued_on,
                payment_terms: draft.payment_terms,
                due_on: draft.due_on,
                items: validItems,
            });
            clearTray();
            reloadList();
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
    const { openFor: openRowSendDialog, modal: rowSendDialog } = useSendAfterCreate(reloadList, reloadList);

    function sendInvoice(invoice) {
        openRowSendDialog(invoice.id, { justCreated: false });
    }

    // Quick action for the common case (a check came in) -- anything else
    // (e.g. "other") is recorded from the invoice detail page instead.
    async function markPaid(invoice) {
        await api.post(`/api/invoices/${invoice.id}/mark-paid`, { method: 'check' });
        reloadList();
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
            reloadList();
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
                        <InvoiceCategorySelect value={draft.category_id ?? ''} onChange={(value) => setDraft({ ...draft, category_id: value })} />
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

            {hasInvoices && (
                <div className="filter-bar">
                    <input
                        type="search"
                        placeholder="Search invoices, clients or projects…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-label="Search invoices"
                        className="input filter-bar__search"
                    />
                    {categories.length > 0 && (
                        <select value={categoryFilter} onChange={(e) => showList({ category: e.target.value, months: MONTHS_STEP })} aria-label="Category" className="input input--inline">
                            <option value="all">All categories</option>
                            <option value="project">{PROJECT_WORK}</option>
                            {categories.map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
                        </select>
                    )}
                    <div className="filter-bar__pills">
                        {STATUS_FILTERS.map((f) => (
                            <button
                                key={f.value}
                                onClick={() => showList({ status: f.value, months: MONTHS_STEP })}
                                className={`filter-bar__pill${filter === f.value ? ' filter-bar__pill--active' : ''}`}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* The add action, directly above the list it adds to. */}
            <TabToolbar addLabel="New invoice" onAdd={openNewInvoice} />
            <div className="card card--flush">
                {!hasInvoices ? (
                    <EmptyState text="No invoices yet." />
                ) : visibleInvoices.length === 0 ? (
                    <EmptyState text={query ? `No invoices match "${query}".` : 'No invoices match this filter.'} />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Client</th>
                                <th>Issued</th>
                                <th>
                                    {canSortByDue ? (
                                        <button onClick={toggleDueSort} className="table__sort">
                                            Due
                                            {sortByDue && <span className="table__sort-indicator">{sortByDue === 'asc' ? '↑' : '↓'}</span>}
                                        </button>
                                    ) : 'Due'}
                                </th>
                                <th>Total</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody ref={rowsRef}>
                            {visibleInvoices.map((invoice, index) => (
                                <Fragment key={invoice.id}>
                                    {!sortByDue && (index === 0 || issuedMonth(visibleInvoices[index - 1]) !== issuedMonth(invoice)) && (
                                        <InvoiceMonth key={`month-${issuedMonth(invoice)}`} month={issuedMonth(invoice)} totals={monthTotals[issuedMonth(invoice)]} />
                                    )}
                                    <tr onClick={(e) => visitRow(e, null, { onOpen: () => openInvoice(invoice.id) })} className="table__row--link">
                                        <td className="table__cell--numeric table__cell--muted">{invoice.invoice_number}</td>
                                        <td className="table__cell--strong">
                                            <div className="table__group">
                                                {invoice.company?.name}
                                                <InvoiceCategoryBadge invoice={invoice} />
                                                <RepeatIcon invoice={invoice} />
                                            </div>
                                        </td>
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
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
            {hasEarlier && !sortByDue && (
                <LoadMore label="Show earlier months" loading={loading} onClick={() => showList({ months: filters.months + MONTHS_STEP })} />
            )}

            {invoiceDrawer}
            {sendDialog}
            {rowSendDialog}
        </AppLayout>
    );
}

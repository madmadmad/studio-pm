import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Check, CheckCircle, Copy, DownloadSimple, Eye, PaperPlaneTilt, PencilSimple, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import Toggle from '../../Components/Toggle';
import InvoiceDateFields, { useInvoiceDateFields } from '../../Components/InvoiceDateFields';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceTotal } from '../../lib/format';
import { calculateDueDate, todayLocal } from '../../lib/paymentTerms';
import { api } from '../../lib/api';
import { getTray, clearTray } from '../../lib/tray';
import { copyToClipboard } from '../../lib/clipboard';
import PageHeader from '../../Components/PageHeader';

function emptyDraft() {
    const issuedOn = todayLocal();
    return {
        company_id: '', contact_id: '', items: [{ description: '', amount: '' }], surcharge: true,
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
                    surcharge: true,
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
    const total = invoiceTotal(draft.items, draft.surcharge);
    const outstandingTotal = invoices
        .filter((i) => i.status === 'sent')
        .reduce((s, i) => s + invoiceTotal(i.items, i.surcharge), 0);

    const visibleInvoices = dueSort
        ? [...invoices].sort((a, b) => (dueSort === 'asc' ? 1 : -1) * (new Date(a.due_on) - new Date(b.due_on)))
        : invoices;

    function toggleDueSort() {
        setDueSort((current) => (current === 'asc' ? 'desc' : current === 'desc' ? null : 'asc'));
    }

    function updateItem(idx, field, value) {
        const items = draft.items.map((it, i) => (i === idx ? { ...it, [field]: value } : it));
        setDraft({ ...draft, items });
    }
    function addItemRow() {
        setDraft({ ...draft, items: [...draft.items, { description: '', amount: '' }] });
    }
    function removeItemRow(idx) {
        setDraft({ ...draft, items: draft.items.filter((_, i) => i !== idx) });
    }

    function openNewInvoice() {
        setDraft(emptyDraft());
        setError('');
        setShowForm(true);
    }

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
                surcharge: draft.surcharge,
                issued_on: draft.issued_on,
                payment_terms: draft.payment_terms,
                due_on: draft.due_on,
                items: validItems,
            });
            if (status === 'sent') {
                await api.post(`/api/invoices/${invoice.id}/send`);
            }
            clearTray();
            setShowForm(false);
            router.reload({ only: ['invoices'] });
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    async function sendInvoice(invoice) {
        await api.post(`/api/invoices/${invoice.id}/send`);
        router.reload({ only: ['invoices'] });
    }

    // Quick action for the common case (a check came in) -- anything else
    // (e.g. "other") is recorded from the invoice detail page instead.
    async function markPaid(invoice) {
        await api.post(`/api/invoices/${invoice.id}/mark-paid`, { method: 'check' });
        router.reload({ only: ['invoices'] });
    }

    async function deleteInvoice(invoice) {
        if (deletingId === invoice.id) return; // already in flight -- ignore a repeat click
        const amount = formatCurrency(invoiceTotal(invoice.items, invoice.surcharge));
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
                subtitle={
                    <>
                        {formatCurrency(outstandingTotal)} outstanding across {invoices.filter((i) => i.status === 'sent').length} sent invoices.
                    </>
                }
            />

            {showForm && (
                <div className="card card--padded mb-6">
                    <div className="grid grid-cols-2 gap-3 mb-4">
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

                    <div className="mb-4">
                        <InvoiceDateFields values={draft} onChange={(patch) => setDraft((current) => ({ ...current, ...patch }))} />
                    </div>

                    <div className="mb-3">
                        {draft.items.map((item, idx) => (
                            <div key={idx} className="mb-2 pb-2 border-b border-border last:border-b-0">
                                <div className="flex gap-2 mb-1">
                                    <input
                                        placeholder="Line item description (required)"
                                        value={item.description}
                                        onChange={(e) => updateItem(idx, 'description', e.target.value)}
                                        className="input flex-1"
                                    />
                                    <input
                                        placeholder="Amount"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={item.amount}
                                        onChange={(e) => updateItem(idx, 'amount', e.target.value)}
                                        className="input tabular-nums w-28"
                                    />
                                    {draft.items.length > 1 && (
                                        <Button variant="link-accent" onClick={() => removeItemRow(idx)}>Remove</Button>
                                    )}
                                </div>
                                <textarea
                                    placeholder="Additional notes shown to the client (optional, not required)"
                                    value={item.details || ''}
                                    onChange={(e) => updateItem(idx, 'details', e.target.value)}
                                    rows={2}
                                    className="input text-xs text-shadow-grey"
                                />
                            </div>
                        ))}
                        <Button variant="link-accent" onClick={addItemRow}>+ Add line item</Button>
                    </div>

                    <div className="text-sm mb-4 space-y-1">
                        <div className="flex justify-between text-shadow-grey">
                            <span>Subtotal</span>
                            <span className="tabular-nums">{formatCurrency(subtotal)}</span>
                        </div>
                        <div className="flex justify-between font-semibold">
                            <span>Total</span>
                            <span className="tabular-nums">{formatCurrency(total)}</span>
                        </div>
                    </div>

                    <div className="mb-4">
                        <Toggle
                            checked={draft.surcharge}
                            onChange={(value) => setDraft({ ...draft, surcharge: value })}
                            label="Offer to pay by card (adds a 3% fee, shown only at checkout)"
                        />
                    </div>

                    {error && <div className="text-sm mb-3 text-watermelon">{error}</div>}

                    <div className="flex gap-2 justify-end">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                        <Button type="button" variant="outline" disabled={saving} onClick={() => saveInvoice('draft')}>Save as draft</Button>
                        <Button type="button" disabled={saving} onClick={() => saveInvoice('sent')}>Send invoice</Button>
                    </div>
                </div>
            )}

            <div className="card overflow-hidden">
                {invoices.length === 0 ? (
                    <EmptyState text="No invoices yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Client</th>
                                <th>Issued</th>
                                <th>
                                    <button onClick={toggleDueSort} className="inline-flex items-center gap-1 hover:text-gunmetal">
                                        Due
                                        {dueSort && <span className="text-xs">{dueSort === 'asc' ? '↑' : '↓'}</span>}
                                    </button>
                                </th>
                                <th>Total</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {visibleInvoices.map((invoice) => (
                                <tr key={invoice.id}>
                                    <td className="tabular-nums text-shadow-grey">{invoice.invoice_number}</td>
                                    <td className="font-medium">
                                        <Link href={`/invoices/${invoice.id}`} className="hover:underline">
                                            {invoice.company?.name}
                                        </Link>
                                    </td>
                                    <td className="text-shadow-grey">{formatDate(invoice.issued_on)}</td>
                                    <td className="text-shadow-grey">{formatDate(invoice.due_on)}</td>
                                    <td className="tabular-nums">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</td>
                                    <td><InvoiceStatusBadge invoice={invoice} /></td>
                                    <td className="text-right">
                                        <div className="flex items-center justify-end gap-3">
                                            <a href={`/i/${invoice.public_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="icon-btn icon-btn--secondary">
                                                <Eye />
                                            </a>
                                            {invoice.status === 'draft' && (
                                                <Link href={`/invoices/${invoice.id}`} title="Edit" className="icon-btn icon-btn--confirm">
                                                    <PencilSimple />
                                                </Link>
                                            )}
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
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}

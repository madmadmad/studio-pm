import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Check, CheckCircle, Copy, Eye, PaperPlaneTilt, PencilSimple, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceSurchargeAmount, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';
import { getTray, clearTray } from '../../lib/tray';
import { copyToClipboard } from '../../lib/clipboard';

function emptyDraft() {
    return { company_id: '', contact_id: '', items: [{ description: '', amount: '' }], surcharge: false };
}

export default function InvoicesIndex({ invoices: invoicesProp, companies }) {
    const [invoices, setInvoices] = useState(invoicesProp);
    const [showForm, setShowForm] = useState(false);
    const [draft, setDraft] = useState(emptyDraft());
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [copiedId, setCopiedId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);

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

    function handleCompanyChange(value) {
        const billingContact = billingContactFor(value);
        setDraft({ ...draft, company_id: value, contact_id: billingContact ? String(billingContact.id) : '' });
    }

    useEffect(() => {
        if (window.location.search.includes('from_tray=1')) {
            const tray = getTray();
            if (tray.length > 0) {
                const companyIds = [...new Set(tray.map((t) => t.company_id))];
                const singleCompanyId = companyIds.length === 1 ? String(companyIds[0]) : '';
                const billingContact = singleCompanyId ? billingContactFor(singleCompanyId) : null;
                setDraft({
                    company_id: singleCompanyId,
                    contact_id: billingContact ? String(billingContact.id) : '',
                    items: tray.map((t) => ({
                        description: t.description,
                        amount: String(t.amount.toFixed(2)),
                        time_entry_ids: [t.time_entry_id],
                    })),
                    surcharge: false,
                });
                setShowForm(true);
            }
            window.history.replaceState(null, '', '/invoices');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const subtotal = invoiceSubtotal(draft.items);
    const surchargeAmount = invoiceSurchargeAmount(draft.items, draft.surcharge);
    const total = invoiceTotal(draft.items, draft.surcharge);
    const outstandingTotal = invoices
        .filter((i) => i.status === 'sent')
        .reduce((s, i) => s + invoiceTotal(i.items, i.surcharge), 0);

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

    async function markPaid(invoice) {
        await api.post(`/api/invoices/${invoice.id}/mark-paid`);
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
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">Invoices</h1>
                <button onClick={openNewInvoice} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">
                    New invoice
                </button>
            </div>
            <p className="text-sm text-sage mb-6">
                {formatCurrency(outstandingTotal)} outstanding across {invoices.filter((i) => i.status === 'sent').length} sent invoices.
            </p>

            {showForm && (
                <div className="bg-white rounded-lg border border-border p-4 mb-6">
                    <div className="grid grid-cols-2 gap-3 mb-4">
                        <select
                            required
                            value={draft.company_id}
                            onChange={(e) => handleCompanyChange(e.target.value)}
                            className="border border-border rounded px-3 py-2 text-sm"
                        >
                            <option value="">Select client</option>
                            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        <select
                            value={draft.contact_id}
                            disabled={!draft.company_id}
                            onChange={(e) => setDraft({ ...draft, contact_id: e.target.value })}
                            className="border border-border rounded px-3 py-2 text-sm disabled:bg-paper disabled:text-sage"
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

                    <div className="mb-3">
                        {draft.items.map((item, idx) => (
                            <div key={idx} className="mb-2 pb-2 border-b border-border last:border-b-0">
                                <div className="flex gap-2 mb-1">
                                    <input
                                        placeholder="Description"
                                        value={item.description}
                                        onChange={(e) => updateItem(idx, 'description', e.target.value)}
                                        className="border border-border rounded px-3 py-2 text-sm flex-1"
                                    />
                                    <input
                                        placeholder="Amount"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={item.amount}
                                        onChange={(e) => updateItem(idx, 'amount', e.target.value)}
                                        className="border border-border rounded px-3 py-2 text-sm tabular-nums w-28"
                                    />
                                    {draft.items.length > 1 && (
                                        <button type="button" onClick={() => removeItemRow(idx)} className="text-sm px-2 text-brick">Remove</button>
                                    )}
                                </div>
                                <textarea
                                    placeholder="Description shown to the client (optional)"
                                    value={item.details || ''}
                                    onChange={(e) => updateItem(idx, 'details', e.target.value)}
                                    rows={2}
                                    className="border border-border rounded px-3 py-2 text-xs text-sage w-full"
                                />
                            </div>
                        ))}
                        <button type="button" onClick={addItemRow} className="text-sm font-medium text-brass">+ Add line item</button>
                    </div>

                    <label className="flex items-center gap-2 text-sm mb-4">
                        <input
                            type="checkbox"
                            checked={draft.surcharge}
                            onChange={(e) => setDraft({ ...draft, surcharge: e.target.checked })}
                        />
                        Client covers card processing fee (3%)
                    </label>

                    <div className="text-sm mb-4 space-y-1">
                        <div className="flex justify-between text-sage">
                            <span>Subtotal</span>
                            <span className="tabular-nums">{formatCurrency(subtotal)}</span>
                        </div>
                        {draft.surcharge && (
                            <div className="flex justify-between text-sage">
                                <span>Card fee (3%)</span>
                                <span className="tabular-nums">{formatCurrency(surchargeAmount)}</span>
                            </div>
                        )}
                        <div className="flex justify-between font-semibold">
                            <span>Total</span>
                            <span className="tabular-nums">{formatCurrency(total)}</span>
                        </div>
                    </div>

                    {error && <div className="text-sm mb-3 text-brick">{error}</div>}

                    <div className="flex gap-2 justify-end">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 rounded text-sage">Cancel</button>
                        <button type="button" disabled={saving} onClick={() => saveInvoice('draft')} className="text-sm font-medium px-3 py-1.5 rounded border border-pine text-pine disabled:opacity-50">Save as draft</button>
                        <button type="button" disabled={saving} onClick={() => saveInvoice('sent')} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Send invoice</button>
                    </div>
                </div>
            )}

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {invoices.length === 0 ? (
                    <EmptyState text="No invoices yet." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">#</th>
                                <th className="px-4 py-2 font-medium">Client</th>
                                <th className="px-4 py-2 font-medium">Issued</th>
                                <th className="px-4 py-2 font-medium">Due</th>
                                <th className="px-4 py-2 font-medium">Total</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                                <th className="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.map((invoice) => (
                                <tr key={invoice.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-3 tabular-nums text-sage">{invoice.invoice_number}</td>
                                    <td className="px-4 py-3 font-medium">
                                        <Link href={`/invoices/${invoice.id}`} className="hover:underline">
                                            {invoice.company?.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-sage">{formatDate(invoice.issued_on)}</td>
                                    <td className="px-4 py-3 text-sage">{formatDate(invoice.due_on)}</td>
                                    <td className="px-4 py-3 tabular-nums">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</td>
                                    <td className="px-4 py-3"><InvoiceStatusBadge invoice={invoice} /></td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-3">
                                            <a href={`/i/${invoice.public_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="text-sage hover:text-ink">
                                                <Eye size={16} />
                                            </a>
                                            {invoice.status === 'draft' && (
                                                <Link href={`/invoices/${invoice.id}`} title="Edit" className="text-pine hover:text-pine/70">
                                                    <PencilSimple size={16} />
                                                </Link>
                                            )}
                                            {invoice.status === 'draft' && (
                                                <button onClick={() => sendInvoice(invoice)} title="Send" className="text-brass hover:text-brass/70">
                                                    <PaperPlaneTilt size={16} />
                                                </button>
                                            )}
                                            {invoice.status !== 'draft' && (
                                                <button onClick={() => copyLink(invoice)} title={copiedId === invoice.id ? 'Copied!' : 'Copy link'} className="text-sage hover:text-ink">
                                                    {copiedId === invoice.id ? <Check size={16} /> : <Copy size={16} />}
                                                </button>
                                            )}
                                            {invoice.status === 'sent' && (
                                                <button onClick={() => markPaid(invoice)} title="Mark paid" className="text-pine hover:text-pine/70">
                                                    <CheckCircle size={16} />
                                                </button>
                                            )}
                                            {invoice.status !== 'paid' && (
                                                <button
                                                    onClick={() => deleteInvoice(invoice)}
                                                    disabled={deletingId === invoice.id}
                                                    title="Delete"
                                                    className="text-sage hover:text-brick disabled:opacity-50"
                                                >
                                                    <Trash size={16} />
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

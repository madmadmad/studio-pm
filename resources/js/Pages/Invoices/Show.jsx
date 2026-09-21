import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, Check, Copy, DownloadSimple, Eye } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import Toggle from '../../Components/Toggle';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';
import { copyToClipboard } from '../../lib/clipboard';

function editFormFrom(invoice) {
    return {
        contact_id: invoice.contact_id ? String(invoice.contact_id) : '',
        surcharge: invoice.surcharge,
        due_on: invoice.due_on ? invoice.due_on.slice(0, 10) : '',
        items: invoice.items.map((item) => ({ description: item.description, details: item.details ?? '', amount: item.amount })),
    };
}

export default function InvoicesShow({ invoice }) {
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState(() => editFormFrom(invoice));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [copied, setCopied] = useState(false);
    const [paymentMethod, setPaymentMethod] = useState('check');
    const [recordingPayment, setRecordingPayment] = useState(false);

    const subtotal = invoiceSubtotal(invoice.items);
    const total = invoiceTotal(invoice.items, invoice.surcharge);

    const formSubtotal = invoiceSubtotal(form.items);
    const formTotal = invoiceTotal(form.items, form.surcharge);

    async function sendInvoice() {
        await api.post(`/api/invoices/${invoice.id}/send`);
        router.reload();
    }

    async function recordPayment() {
        setRecordingPayment(true);
        try {
            await api.post(`/api/invoices/${invoice.id}/mark-paid`, { method: paymentMethod });
            router.reload();
        } finally {
            setRecordingPayment(false);
        }
    }

    async function copyLink() {
        const ok = await copyToClipboard(`${window.location.origin}/i/${invoice.public_token}`);
        if (!ok) {
            alert('Could not copy the link. Copy it manually instead.');
            return;
        }
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    }

    function startEditing() {
        setForm(editFormFrom(invoice));
        setError('');
        setEditing(true);
    }

    function updateItem(idx, field, value) {
        const items = form.items.map((it, i) => (i === idx ? { ...it, [field]: value } : it));
        setForm({ ...form, items });
    }
    function addItemRow() {
        setForm({ ...form, items: [...form.items, { description: '', amount: '' }] });
    }
    function removeItemRow(idx) {
        setForm({ ...form, items: form.items.filter((_, i) => i !== idx) });
    }

    async function save() {
        const validItems = form.items.filter((i) => i.description.trim() && parseFloat(i.amount) > 0);
        if (validItems.length === 0) {
            setError('Add at least one line item with a description and amount.');
            return;
        }
        setSaving(true);
        setError('');
        try {
            await api.patch(`/api/invoices/${invoice.id}`, {
                contact_id: form.contact_id || null,
                surcharge: form.surcharge,
                due_on: form.due_on || null,
                items: validItems,
            });
            setEditing(false);
            router.reload();
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <AppLayout>
            <Head title={`Invoice — ${invoice.company.name}`} />
            <div className="max-w-3xl">
            <div className="mb-1">
                <Link href="/invoices" className="text-sm text-shadow-grey hover:underline inline-flex items-center gap-1">
                    <ArrowLeft size={14} /> Invoices
                </Link>
            </div>
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">{invoice.company.name}</h1>
                <div className="flex items-center gap-3">
                    <InvoiceStatusBadge invoice={invoice} />
                    <a href={`/i/${invoice.public_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="icon-btn icon-btn-secondary">
                        <Eye size={20} />
                    </a>
                    <button onClick={copyLink} title={copied ? 'Copied!' : 'Copy link'} className="icon-btn icon-btn-secondary">
                        {copied ? <Check size={20} /> : <Copy size={20} />}
                    </button>
                    {invoice.status !== 'draft' && (
                        <a href={`/invoices/${invoice.id}/pdf`} title="Download PDF" className="icon-btn icon-btn-secondary">
                            <DownloadSimple size={20} />
                        </a>
                    )}
                    {invoice.status === 'draft' && !editing && (
                        <Button variant="link" onClick={startEditing}>Edit</Button>
                    )}
                </div>
            </div>
            <p className="text-sm text-shadow-grey mb-6">
                Invoice #{invoice.invoice_number} &middot; Issued {formatDate(invoice.issued_on)} &middot; Due {formatDate(invoice.due_on)}
                {invoice.contact && <> &middot; Billed to {invoice.contact.name}</>}
                {invoice.project?.po_number && <> &middot; PO #{invoice.project.po_number}</>}
            </p>

            {editing ? (
                <div className="card card-padded mb-6">
                    <div className="grid grid-cols-2 gap-3 mb-4">
                        <select
                            value={form.contact_id}
                            onChange={(e) => setForm({ ...form, contact_id: e.target.value })}
                            className="field"
                        >
                            <option value="">Bill to (no specific contact)</option>
                            {invoice.company.contacts.map((contact) => (
                                <option key={contact.id} value={contact.id}>
                                    {contact.name}{contact.email ? ` (${contact.email})` : ''}
                                </option>
                            ))}
                        </select>
                        <input
                            type="date"
                            value={form.due_on}
                            onChange={(e) => setForm({ ...form, due_on: e.target.value })}
                            className="field"
                        />
                    </div>

                    <div className="mb-3">
                        {form.items.map((item, idx) => (
                            <div key={idx} className="mb-2 pb-2 border-b border-border last:border-b-0">
                                <div className="flex gap-2 mb-1">
                                    <input
                                        placeholder="Description"
                                        value={item.description}
                                        onChange={(e) => updateItem(idx, 'description', e.target.value)}
                                        className="field flex-1"
                                    />
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="Amount"
                                        value={item.amount}
                                        onChange={(e) => updateItem(idx, 'amount', e.target.value)}
                                        className="field tabular-nums w-28"
                                    />
                                    {form.items.length > 1 && (
                                        <Button variant="link-danger" onClick={() => removeItemRow(idx)}>Remove</Button>
                                    )}
                                </div>
                                <textarea
                                    placeholder="Description shown to the client (optional)"
                                    value={item.details || ''}
                                    onChange={(e) => updateItem(idx, 'details', e.target.value)}
                                    rows={2}
                                    className="field text-xs text-shadow-grey"
                                />
                            </div>
                        ))}
                        <Button variant="link-accent" onClick={addItemRow}>+ Add line item</Button>
                    </div>

                    <div className="text-sm mb-4 space-y-1">
                        <div className="flex justify-between text-shadow-grey">
                            <span>Subtotal</span>
                            <span className="tabular-nums">{formatCurrency(formSubtotal)}</span>
                        </div>
                        <div className="flex justify-between font-semibold">
                            <span>Total</span>
                            <span className="tabular-nums">{formatCurrency(formTotal)}</span>
                        </div>
                    </div>

                    <div className="mb-4">
                        <Toggle
                            checked={form.surcharge}
                            onChange={(value) => setForm({ ...form, surcharge: value })}
                            label="Offer to pay by card (adds a 3% fee, shown only at checkout)"
                        />
                    </div>

                    {error && <div className="text-sm text-fuchsia mb-3">{error}</div>}

                    <div className="flex gap-2 justify-end">
                        <Button variant="secondary" onClick={() => setEditing(false)}>Cancel</Button>
                        <Button variant="confirm" disabled={saving} onClick={save}>Save</Button>
                    </div>
                </div>
            ) : (
                <div className="card card-padded mb-6">
                    <table className="table table-flush mb-4">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th className="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.items.map((item) => (
                                <tr key={item.id}>
                                    <td>
                                        {item.description}
                                        {item.details && item.details !== item.description && (
                                            <div className="text-xs text-shadow-grey mt-1 whitespace-pre-wrap">{item.details}</div>
                                        )}
                                    </td>
                                    <td className="text-right tabular-nums align-top">{formatCurrency(item.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="text-sm space-y-1 ml-auto max-w-xs">
                        <div className="flex justify-between text-shadow-grey">
                            <span>Subtotal</span>
                            <span className="tabular-nums">{formatCurrency(subtotal)}</span>
                        </div>
                        <div className="flex justify-between font-semibold">
                            <span>Total</span>
                            <span className="tabular-nums">{formatCurrency(total)}</span>
                        </div>
                    </div>
                </div>
            )}

            {invoice.payments.length > 0 && (
                <div className="card card-padded mb-6">
                    <h2 className="text-sm font-semibold text-shadow-grey mb-3">Payments</h2>
                    <ul className="text-sm divide-y divide-border">
                        {invoice.payments.map((payment) => (
                            <li key={payment.id} className="py-2 flex justify-between">
                                <span>{formatDate(payment.paid_at)}{payment.method ? ` · ${payment.method}` : ''}</span>
                                <span className="tabular-nums">{formatCurrency(parseFloat(payment.amount) + parseFloat(payment.surcharge_amount))}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {!editing && (
                <div className="flex items-center gap-2">
                    {invoice.status === 'draft' && (
                        <Button onClick={sendInvoice}>Send invoice</Button>
                    )}
                    {invoice.status === 'sent' && (
                        <>
                            <select
                                value={paymentMethod}
                                onChange={(e) => setPaymentMethod(e.target.value)}
                                className="field field-sm w-auto"
                            >
                                <option value="check">Check</option>
                                <option value="other">Other</option>
                            </select>
                            <Button variant="confirm" onClick={recordPayment} disabled={recordingPayment}>
                                Record payment
                            </Button>
                        </>
                    )}
                </div>
            )}
            </div>
        </AppLayout>
    );
}

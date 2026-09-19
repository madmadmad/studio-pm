import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceSurchargeAmount, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';

function editFormFrom(invoice) {
    return {
        contact_id: invoice.contact_id ? String(invoice.contact_id) : '',
        surcharge: invoice.surcharge,
        due_on: invoice.due_on ? invoice.due_on.slice(0, 10) : '',
        items: invoice.items.map((item) => ({ description: item.description, amount: item.amount })),
    };
}

export default function InvoicesShow({ invoice }) {
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState(() => editFormFrom(invoice));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const subtotal = invoiceSubtotal(invoice.items);
    const surchargeAmount = invoiceSurchargeAmount(invoice.items, invoice.surcharge);
    const total = invoiceTotal(invoice.items, invoice.surcharge);

    async function sendInvoice() {
        await api.post(`/api/invoices/${invoice.id}/send`);
        router.reload();
    }

    async function markPaid() {
        await api.post(`/api/invoices/${invoice.id}/mark-paid`);
        router.reload();
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
                <Link href="/invoices" className="text-sm text-sage hover:underline inline-flex items-center gap-1">
                    <ArrowLeft size={14} /> Invoices
                </Link>
            </div>
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">{invoice.company.name}</h1>
                <div className="flex items-center gap-3">
                    <InvoiceStatusBadge invoice={invoice} />
                    {invoice.status === 'draft' && !editing && (
                        <button onClick={startEditing} className="text-sm font-medium text-pine">Edit</button>
                    )}
                </div>
            </div>
            <p className="text-sm text-sage mb-6">
                Issued {formatDate(invoice.issued_on)} &middot; Due {formatDate(invoice.due_on)}
                {invoice.contact && <> &middot; Billed to {invoice.contact.name}</>}
            </p>

            {editing ? (
                <div className="bg-white rounded-lg border border-border p-4 mb-6">
                    <div className="grid grid-cols-2 gap-3 mb-4">
                        <select
                            value={form.contact_id}
                            onChange={(e) => setForm({ ...form, contact_id: e.target.value })}
                            className="border border-border rounded px-3 py-2 text-sm"
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
                            className="border border-border rounded px-3 py-2 text-sm"
                        />
                    </div>

                    <div className="mb-3">
                        {form.items.map((item, idx) => (
                            <div key={idx} className="flex gap-2 mb-2">
                                <input
                                    placeholder="Description"
                                    value={item.description}
                                    onChange={(e) => updateItem(idx, 'description', e.target.value)}
                                    className="border border-border rounded px-3 py-2 text-sm flex-1"
                                />
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="Amount"
                                    value={item.amount}
                                    onChange={(e) => updateItem(idx, 'amount', e.target.value)}
                                    className="border border-border rounded px-3 py-2 text-sm tabular-nums w-28"
                                />
                                {form.items.length > 1 && (
                                    <button type="button" onClick={() => removeItemRow(idx)} className="text-sm px-2 text-brick">Remove</button>
                                )}
                            </div>
                        ))}
                        <button type="button" onClick={addItemRow} className="text-sm font-medium text-brass">+ Add line item</button>
                    </div>

                    <label className="flex items-center gap-2 text-sm mb-4">
                        <input type="checkbox" checked={form.surcharge} onChange={(e) => setForm({ ...form, surcharge: e.target.checked })} />
                        Client covers card processing fee (3%)
                    </label>

                    {error && <div className="text-sm text-brick mb-3">{error}</div>}

                    <div className="flex gap-2 justify-end">
                        <button type="button" onClick={() => setEditing(false)} className="text-sm px-3 py-1.5 rounded text-sage">Cancel</button>
                        <button type="button" disabled={saving} onClick={save} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Save</button>
                    </div>
                </div>
            ) : (
                <div className="bg-white rounded-lg border border-border p-4 mb-6">
                    <table className="w-full text-sm mb-4">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="py-2 font-medium">Description</th>
                                <th className="py-2 font-medium text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.items.map((item) => (
                                <tr key={item.id} className="border-b border-border last:border-b-0">
                                    <td className="py-2">{item.description}</td>
                                    <td className="py-2 text-right tabular-nums">{formatCurrency(item.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="text-sm space-y-1 ml-auto max-w-xs">
                        <div className="flex justify-between text-sage">
                            <span>Subtotal</span>
                            <span className="tabular-nums">{formatCurrency(subtotal)}</span>
                        </div>
                        {invoice.surcharge && (
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
                </div>
            )}

            {invoice.payments.length > 0 && (
                <div className="bg-white rounded-lg border border-border p-4 mb-6">
                    <h2 className="text-sm font-semibold text-sage mb-3">Payments</h2>
                    <ul className="text-sm divide-y divide-border">
                        {invoice.payments.map((payment) => (
                            <li key={payment.id} className="py-2 flex justify-between">
                                <span>{formatDate(payment.paid_at)}</span>
                                <span className="tabular-nums">{formatCurrency(parseFloat(payment.amount) + parseFloat(payment.surcharge_amount))}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {!editing && (
                <div className="flex gap-2">
                    {invoice.status === 'draft' && (
                        <button onClick={sendInvoice} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">Send invoice</button>
                    )}
                    {invoice.status === 'sent' && (
                        <button onClick={markPaid} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded">Mark paid</button>
                    )}
                </div>
            )}
            </div>
        </AppLayout>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Check, Copy, DownloadSimple, Eye, PaperPlaneTilt } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import Toggle from '../../Components/Toggle';
import InvoiceDateFields from '../../Components/InvoiceDateFields';
import SendInvoiceModal from '../../Components/SendInvoiceModal';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceTotal } from '../../lib/format';
import { formatDateTimeEastern, utcToEasternParts, easternWallTimeToUtcIso } from '../../lib/datetime';
import { reminderRows } from '../../lib/reminders';
import { paymentTermsLabel } from '../../lib/paymentTerms';
import { api } from '../../lib/api';
import { copyToClipboard } from '../../lib/clipboard';
import PageHeader from '../../Components/PageHeader';

function editFormFrom(invoice) {
    return {
        contact_id: invoice.contact_id ? String(invoice.contact_id) : '',
        surcharge: invoice.surcharge,
        issued_on: invoice.issued_on ? invoice.issued_on.slice(0, 10) : '',
        payment_terms: invoice.payment_terms,
        due_on: invoice.due_on ? invoice.due_on.slice(0, 10) : '',
        items: invoice.items.map((item) => ({ description: item.description, details: item.details ?? '', amount: item.amount })),
    };
}

export default function InvoicesShow({ invoice: initialInvoice, studio, invoicingDefaults }) {
    const [invoice, setInvoice] = useState(initialInvoice);
    useEffect(() => setInvoice(initialInvoice), [initialInvoice]);

    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState(() => editFormFrom(invoice));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [copied, setCopied] = useState(false);
    const [paymentMethod, setPaymentMethod] = useState('check');
    const [recordingPayment, setRecordingPayment] = useState(false);
    const [sendModalOpen, setSendModalOpen] = useState(false);
    const [successMessage, setSuccessMessage] = useState('');
    const [reschedulingId, setReschedulingId] = useState(null);
    const [rescheduleDate, setRescheduleDate] = useState('');
    const [rescheduleTime, setRescheduleTime] = useState('');

    const subtotal = invoiceSubtotal(invoice.items);
    const total = invoiceTotal(invoice.items, invoice.surcharge);

    const formSubtotal = invoiceSubtotal(form.items);
    const formTotal = invoiceTotal(form.items, form.surcharge);

    function handleSent(updatedInvoice, message) {
        setInvoice(updatedInvoice);
        setSendModalOpen(false);
        setSuccessMessage(message);
        setTimeout(() => setSuccessMessage(''), 6000);
    }

    const pendingScheduledSend = (invoice.invoice_sends || []).find((s) => s.type === 'email' && s.status === 'scheduled');

    async function cancelScheduledSend(send) {
        const updated = await api.post(`/api/invoices/${invoice.id}/sends/${send.id}/cancel`);
        setInvoice((current) => ({ ...current, invoice_sends: current.invoice_sends.map((s) => (s.id === updated.id ? updated : s)) }));
    }

    async function sendScheduledNow(send) {
        const updated = await api.post(`/api/invoices/${invoice.id}/sends/${send.id}/send-now`);
        setInvoice((current) => ({ ...current, invoice_sends: current.invoice_sends.map((s) => (s.id === updated.id ? updated : s)) }));
    }

    function startRescheduling(send) {
        const parts = utcToEasternParts(send.scheduled_for);
        setReschedulingId(send.id);
        setRescheduleDate(parts.date);
        setRescheduleTime(parts.time);
    }

    async function confirmReschedule(send) {
        const scheduledFor = easternWallTimeToUtcIso(rescheduleDate, rescheduleTime);
        const updated = await api.post(`/api/invoices/${invoice.id}/sends/${send.id}/reschedule`, { scheduled_for: scheduledFor });
        setInvoice((current) => ({ ...current, invoice_sends: current.invoice_sends.map((s) => (s.id === updated.id ? updated : s)) }));
        setReschedulingId(null);
    }

    async function skipReminder(rule) {
        const created = await api.post(`/api/invoices/${invoice.id}/reminders/skip`, { rule });
        setInvoice((current) => ({ ...current, invoice_sends: [created, ...(current.invoice_sends || [])] }));
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
                issued_on: form.issued_on,
                payment_terms: form.payment_terms,
                due_on: form.due_on,
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
            <div className="max-w-4xl">
            <PageHeader
                back={{ href: '/invoices', label: 'Invoices' }}
                title={invoice.company.name}
                actions={
                    <>
                        <InvoiceStatusBadge invoice={invoice} />
                        <a href={`/i/${invoice.public_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="icon-btn icon-btn-secondary">
                            <Eye />
                        </a>
                        <button onClick={copyLink} title={copied ? 'Copied!' : 'Copy link'} className="icon-btn icon-btn-secondary">
                            {copied ? <Check /> : <Copy />}
                        </button>
                        {invoice.status !== 'draft' && (
                            <a href={`/invoices/${invoice.id}/pdf`} title="Download PDF" className="icon-btn icon-btn-secondary">
                                <DownloadSimple />
                            </a>
                        )}
                        {invoice.status !== 'paid' && (
                            <button
                                onClick={() => setSendModalOpen(true)}
                                disabled={editing}
                                title={editing ? 'Save or cancel your edits first' : invoice.sent_at ? 'Resend' : 'Send invoice'}
                                className="icon-btn icon-btn-accent"
                            >
                                <PaperPlaneTilt />
                            </button>
                        )}
                        {invoice.status === 'draft' && !editing && (
                            <Button variant="link" onClick={startEditing}>Edit</Button>
                        )}
                    </>
                }
                subtitle={
                    <>
                        Invoice #{invoice.invoice_number} &middot; Issued {formatDate(invoice.issued_on)} &middot; Due {formatDate(invoice.due_on)}
                        {paymentTermsLabel(invoice.payment_terms) !== 'Custom' && ` (${paymentTermsLabel(invoice.payment_terms)})`}
                        {invoice.contact && <> &middot; Billed to {invoice.contact.name}</>}
                        {invoice.project?.po_number && <> &middot; PO #{invoice.project.po_number}</>}
                    </>
                }
            />

            {successMessage && (
                <div role="status" aria-live="polite" className="text-sm text-fern bg-fern-soft rounded px-3 py-2 mb-4">
                    {successMessage}
                </div>
            )}

            {editing && invoice.sent_at && (
                <div className="text-sm text-shadow-grey bg-porcelain rounded px-3 py-2 mb-4">
                    This invoice has already been sent. The client won&rsquo;t see changes in their original email until you resend it; the online link always shows the latest version.
                </div>
            )}

            {editing ? (
                <div className="card card-padded mb-6">
                    <div className="mb-4">
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
                    </div>

                    <div className="mb-4">
                        <InvoiceDateFields values={form} onChange={(patch) => setForm((current) => ({ ...current, ...patch }))} />
                    </div>

                    <div className="mb-3">
                        {form.items.map((item, idx) => (
                            <div key={idx} className="mb-2 pb-2 border-b border-border last:border-b-0">
                                <div className="flex gap-2 mb-1">
                                    <input
                                        placeholder="Line item description (required)"
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
                                    placeholder="Additional notes shown to the client (optional, not required)"
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

                    {error && <div className="text-sm text-watermelon mb-3">{error}</div>}

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

            {pendingScheduledSend && (
                <div className="card card-padded mb-6">
                    <div className="flex items-center justify-between">
                        <span className="text-sm">
                            Scheduled for <strong>{formatDateTimeEastern(pendingScheduledSend.scheduled_for)}</strong>
                        </span>
                        <div className="flex items-center gap-2">
                            <Button variant="link" onClick={() => sendScheduledNow(pendingScheduledSend)}>Send now</Button>
                            <Button variant="link" onClick={() => startRescheduling(pendingScheduledSend)}>Reschedule</Button>
                            <Button variant="link-danger" onClick={() => cancelScheduledSend(pendingScheduledSend)}>Cancel</Button>
                        </div>
                    </div>
                    {reschedulingId === pendingScheduledSend.id && (
                        <div className="flex items-center gap-2 mt-3">
                            <input type="date" value={rescheduleDate} onChange={(e) => setRescheduleDate(e.target.value)} className="field field-sm" />
                            <input type="time" value={rescheduleTime} onChange={(e) => setRescheduleTime(e.target.value)} className="field field-sm" />
                            <Button variant="confirm" onClick={() => confirmReschedule(pendingScheduledSend)}>Save</Button>
                            <Button variant="secondary" onClick={() => setReschedulingId(null)}>Cancel</Button>
                        </div>
                    )}
                </div>
            )}

            {invoice.status !== 'draft' && invoice.status !== 'paid' && (
                <div className="card card-padded mb-6">
                    <h2 className="text-sm font-semibold text-shadow-grey mb-3">Reminders</h2>
                    <ul className="text-sm divide-y divide-border">
                        {reminderRows(invoice).map((row) => (
                            <li key={row.rule} className="py-2 flex items-center justify-between">
                                <span>
                                    {row.label} &middot; {formatDate(row.date.toISOString())}
                                    {row.status === 'sent' && <span className="text-fern"> &middot; Sent</span>}
                                    {row.status === 'cancelled' && <span className="text-shadow-grey"> &middot; Skipped</span>}
                                    {row.status === 'failed' && <span className="text-watermelon"> &middot; Failed</span>}
                                </span>
                                {row.status === 'upcoming' && (
                                    <Button variant="link-danger" onClick={() => skipReminder(row.rule)}>Skip</Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {invoice.invoice_sends?.length > 0 && (
                <div className="card card-padded mb-6">
                    <h2 className="text-sm font-semibold text-shadow-grey mb-3">History</h2>
                    <ul className="text-sm divide-y divide-border">
                        {invoice.invoice_sends.map((send) => (
                            <li key={send.id} className="py-2">
                                {send.type === 'link' && <>Link copied{send.sent_by ? ` by ${send.sent_by.name}` : ''}, {formatDateTimeEastern(send.sent_at)}</>}
                                {send.type === 'email' && send.status === 'sent' && <>Sent to {(send.recipients || []).join(', ')}, {formatDateTimeEastern(send.sent_at)}</>}
                                {send.type === 'email' && send.status === 'scheduled' && <>Scheduled for {formatDateTimeEastern(send.scheduled_for)}</>}
                                {send.type === 'email' && send.status === 'cancelled' && <>Scheduled send cancelled{send.failure_reason ? `: ${send.failure_reason}` : ''}</>}
                                {send.type === 'email' && send.status === 'failed' && <span className="text-watermelon">Send failed{send.failure_reason ? `: ${send.failure_reason}` : ''}</span>}
                                {send.type === 'reminder' && send.status === 'sent' && <>Reminder sent to {(send.recipients || []).join(', ')}, {formatDateTimeEastern(send.sent_at)}</>}
                                {send.type === 'reminder' && send.status === 'cancelled' && <>Reminder skipped{send.failure_reason ? `: ${send.failure_reason}` : ''}</>}
                                {send.type === 'reminder' && send.status === 'failed' && <span className="text-watermelon">Reminder failed{send.failure_reason ? `: ${send.failure_reason}` : ''}</span>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {!editing && error && <div className="text-sm text-watermelon mb-3">{error}</div>}

            {!editing && invoice.status === 'sent' && (
                <div className="flex items-center gap-2">
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
                </div>
            )}
            </div>

            {sendModalOpen && (
                <SendInvoiceModal
                    invoice={invoice}
                    studio={studio}
                    invoicingDefaults={invoicingDefaults}
                    onClose={() => setSendModalOpen(false)}
                    onSent={handleSent}
                />
            )}
        </AppLayout>
    );
}

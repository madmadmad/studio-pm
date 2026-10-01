import { useEffect, useState } from 'react';
import { Check, Copy, DownloadSimple, Eye, PaperPlaneTilt, PencilSimple } from '@phosphor-icons/react';
import Button from './Button';
import InvoiceLineItems from './InvoiceLineItems';
import InvoiceDateFields from './InvoiceDateFields';
import SendInvoiceModal from './SendInvoiceModal';
import { TaxRow, TaxToggle, useSalesTax } from './InvoiceTax';
import { InvoiceCategorySelect } from './InvoiceCategory';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceTotal } from '../lib/format';
import { formatDateTimeEastern, utcToEasternParts, easternWallTimeToUtcIso } from '../lib/datetime';
import { reminderRows } from '../lib/reminders';
import { paymentTermsLabel } from '../lib/paymentTerms';
import { api } from '../lib/api';
import { copyToClipboard } from '../lib/clipboard';
import { hasQueuedEmail, waitForQueuedSend } from '../lib/invoiceSends';

// The invoice detail, shared by the standalone page (Pages/Invoices/Show)
// and the project page's invoice drawer. The caller supplies the frame
// through `renderFrame({ invoice, actions, children })` -- a page header
// or a drawer -- and `onChange` to refresh after a save or payment.
// `bare` lays the sections out plainly (no cards), for inside a drawer.
// `openInEdit` starts a draft in its edit form (the drawer does), where
// Cancel, Save and Send call `onDone()` -- there's no read view to go
// back to -- and `onDone({ close: false })` once a queued send goes out.

function editFormFrom(invoice) {
    return {
        contact_id: invoice.contact_id ? String(invoice.contact_id) : '',
        category_id: invoice.category_id ? String(invoice.category_id) : '',
        tax_rate: invoice.tax_rate,
        tax_name: invoice.tax_name,
        issued_on: invoice.issued_on ? invoice.issued_on.slice(0, 10) : '',
        payment_terms: invoice.payment_terms,
        due_on: invoice.due_on ? invoice.due_on.slice(0, 10) : '',
        // Each line keeps its id, so the save updates it in place and the
        // time and expenses billed to it stay billed.
        items: invoice.items.map((item) => ({
            id: item.id, description: item.description, details: item.details ?? '', amount: item.amount, taxable: item.taxable,
            // Marks a line that bills an expense; removing it unbills it.
            from_expense: Boolean(item.expense),
        })),
    };
}

// "Due Oct 19, 2026 (Net 30) · Billed to Jo Park · PO #1234"
export function InvoiceDueLine({ invoice }) {
    return (
        <>
            Due {formatDate(invoice.due_on)}
            {paymentTermsLabel(invoice.payment_terms) !== 'Custom' && ` (${paymentTermsLabel(invoice.payment_terms)})`}
            {invoice.contact && <> &middot; Billed to {invoice.contact.name}</>}
            {invoice.project?.po_number && <> &middot; PO #{invoice.project.po_number}</>}
            {invoice.category && <> &middot; {invoice.category.name}</>}
        </>
    );
}

export default function InvoiceDetail({ invoice: initialInvoice, studio, invoicingDefaults, onChange, renderFrame, bare = false, openInEdit = false, onDone }) {
    const [invoice, setInvoice] = useState(initialInvoice);
    useEffect(() => setInvoice(initialInvoice), [initialInvoice]);

    // A draft opened with `openInEdit` goes straight to its edit form.
    const editsInPlace = openInEdit && initialInvoice.status === 'draft';
    const [editing, setEditing] = useState(editsInPlace);
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

    // A card on the page; in a drawer, a subtle panel per section (the
    // drawer is already the raised layer), under a ruled label.
    const section = bare ? 'form-panel' : 'card card--padded page-section';
    const heading = (text) => (bare
        ? <div className="section-label section-label--ruled">{text}</div>
        : <h2 className="section-heading">{text}</h2>);

    const subtotal = invoiceSubtotal(invoice.items);
    const total = invoiceTotal(invoice.items, invoice.surcharge, invoice.tax_rate);

    const formSubtotal = invoiceSubtotal(form.items);
    const formTotal = invoiceTotal(form.items, form.surcharge, form.tax_rate);
    const salesTax = useSalesTax(invoice);

    function handleSent(updatedInvoice, message) {
        setInvoice(updatedInvoice);
        setSendModalOpen(false);
        setSuccessMessage(message);
        setTimeout(() => setSuccessMessage(''), 6000);
        // Let the container catch up too (the project list's status badge).
        onChange();
        // An email is still on the queue here; once it's gone out the
        // status changes, so catch up again then.
        if (hasQueuedEmail(updatedInvoice)) {
            waitForQueuedSend(updatedInvoice.id).then((latest) => {
                if (!latest) return;
                setInvoice(latest);
                onChange();
            });
        }
    }

    const pendingScheduledSend = (invoice.invoice_sends || []).find((s) => s.type === 'email' && s.status === 'scheduled');

    // Ends a repeating series: no more copies.
    async function stopRepeating() {
        if (!confirm(`Stop sending this invoice ${invoice.repeat === 'yearly' ? 'every year' : 'every month'}? Copies already made stay as they are.`)) return;
        const updated = await api.post(`/api/invoices/${invoice.id}/repeat/stop`);
        setInvoice((current) => ({ ...current, ...updated }));
        onChange();
    }

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
            onChange();
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


    function cancelEditing() {
        if (editsInPlace) onDone();
        else setEditing(false);
    }

    // `send` saves, then opens the Send Invoice dialog on the saved invoice.
    async function save({ send = false } = {}) {
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
                category_id: form.category_id || null,
                tax: form.tax_rate != null,
                issued_on: form.issued_on,
                payment_terms: form.payment_terms,
                due_on: form.due_on,
                items: validItems,
            });
            if (send) {
                const latest = await api.get(`/api/invoices/${invoice.id}`);
                setInvoice(latest.invoice);
                setEditing(false);
                setSendModalOpen(true);
                return;
            }
            if (editsInPlace) {
                onDone();
                return;
            }
            setEditing(false);
            // The client's email and PDF are the version they were sent; only
            // the online link updates by itself.
            if (invoice.sent_at) {
                setSuccessMessage('Changes saved. The online link shows them now — resend the invoice if the client needs an updated email or PDF.');
                setTimeout(() => setSuccessMessage(''), 8000);
            }
            onChange();
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    const actions = (
        <>
            <a href={`/i/${invoice.public_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="icon-btn icon-btn--secondary">
                <Eye />
            </a>
            <button onClick={copyLink} title={copied ? 'Copied!' : 'Copy link'} className="icon-btn icon-btn--secondary">
                {copied ? <Check /> : <Copy />}
            </button>
            {invoice.status !== 'draft' && (
                <a href={`/invoices/${invoice.id}/pdf`} title="Download PDF" className="icon-btn icon-btn--secondary">
                    <DownloadSimple />
                </a>
            )}
            {invoice.status !== 'paid' && (
                <button
                    // While editing, it saves the edits first.
                    onClick={() => (editing ? save({ send: true }) : setSendModalOpen(true))}
                    disabled={saving}
                    title={editing ? 'Save and send' : invoice.sent_at ? 'Resend' : 'Send invoice'}
                    className="icon-btn icon-btn--accent"
                >
                    <PaperPlaneTilt />
                </button>
            )}
            {/* Drafts and sent invoices can be edited; paid ones are locked. */}
            {invoice.status !== 'paid' && !editing && (
                <button onClick={startEditing} title="Edit invoice" aria-label="Edit invoice" className="icon-btn icon-btn--edit">
                    <PencilSimple />
                </button>
            )}
        </>
    );

    return renderFrame({
        invoice,
        actions,
        children: (
            <>
                {successMessage && (
                    <div role="status" aria-live="polite" className="alert alert--success">
                        {successMessage}
                    </div>
                )}

                {editing && invoice.sent_at && (
                    <div className="alert alert--info">
                        This invoice has already been sent. The client won&rsquo;t see changes in their original email until you resend it; the online link always shows the latest version.
                    </div>
                )}

                {editing ? (
                    <div className={`${bare ? 'drawer__section' : section} invoice-form`}>
                        <div className="invoice-form__section invoice-form__parties">
                            <select
                                value={form.contact_id}
                                onChange={(e) => setForm({ ...form, contact_id: e.target.value })}
                                className="input"
                            >
                                <option value="">Bill to (no specific contact)</option>
                                {invoice.company.contacts.map((contact) => (
                                    <option key={contact.id} value={contact.id}>
                                        {contact.name}{contact.email ? ` (${contact.email})` : ''}
                                    </option>
                                ))}
                            </select>
                            <InvoiceCategorySelect value={form.category_id} onChange={(value) => setForm({ ...form, category_id: value })} />
                        </div>

                        <div className="form-panel">
                            <div className="section-label section-label--ruled">Dates &amp; terms</div>
                            <InvoiceDateFields values={form} onChange={(patch) => setForm((current) => ({ ...current, ...patch }))} />
                        </div>

                        <InvoiceLineItems items={form.items} projectId={invoice.project_id} taxed={form.tax_rate != null} onChange={(items) => setForm((current) => ({ ...current, items }))} />

                        <div className="invoice-form__section totals">
                            <div className="totals__row totals__row--muted">
                                <span>Subtotal</span>
                                <span className="totals__value">{formatCurrency(formSubtotal)}</span>
                            </div>
                            <TaxRow items={form.items} taxName={form.tax_name} taxRate={form.tax_rate} />
                            <div className="totals__row totals__row--strong">
                                <span>Total</span>
                                <span className="totals__value">{formatCurrency(formTotal)}</span>
                            </div>
                        </div>

                        {error && <div className="form-message form-message--error form-message--spaced">{error}</div>}
                        {/* The tax toggle on the left, the form's buttons on the right. */}
                        <div className="invoice-form__footer">
                            <TaxToggle form={form} salesTax={salesTax} onChange={(patch) => setForm((current) => ({ ...current, ...patch }))} />
                            <div className="form-actions">
                                <Button variant="secondary" onClick={cancelEditing}>Cancel</Button>
                                {invoice.status === 'draft' ? (
                                    <>
                                        <Button variant="secondary" disabled={saving} onClick={() => save()}>Save as draft</Button>
                                        <Button variant="confirm" disabled={saving} onClick={() => save({ send: true })}>Send invoice</Button>
                                    </>
                                ) : (
                                    <Button variant="confirm" disabled={saving} onClick={() => save()}>Save</Button>
                                )}
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className={section}>
                        <table className="table table--flush card__section">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th className="table__cell--end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.items.map((item) => (
                                    <tr key={item.id}>
                                        <td>
                                            <div className="table__item-name">{item.description}</div>
                                            {item.details && item.details !== item.description && (
                                                <div className="table__meta table__meta--multiline">{item.details}</div>
                                            )}
                                            {invoice.tax_rate != null && item.taxable && <div className="table__meta">Taxable</div>}
                                        </td>
                                        <td className="table__cell--end table__cell--numeric table__cell--top">{formatCurrency(item.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <div className="totals totals--aside">
                            <div className="totals__row totals__row--muted">
                                <span>Subtotal</span>
                                <span className="totals__value">{formatCurrency(subtotal)}</span>
                            </div>
                            <TaxRow items={invoice.items} taxName={invoice.tax_name} taxRate={invoice.tax_rate} />
                            <div className="totals__row totals__row--strong">
                                <span>Total</span>
                                <span className="totals__value">{formatCurrency(total)}</span>
                            </div>
                        </div>
                    </div>
                )}

                {invoice.payments.length > 0 && (
                    <div className={section}>
                        {heading('Payments')}
                        <ul className="detail-list">
                            {invoice.payments.map((payment) => (
                                <li key={payment.id} className="detail-list__item detail-list__item--split">
                                    <span>{formatDate(payment.paid_at)}{payment.method ? ` · ${payment.method}` : ''}</span>
                                    <span className="detail-list__amount">{formatCurrency(parseFloat(payment.amount) + parseFloat(payment.surcharge_amount))}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* A repeating series (set when it was sent), or a copy of one. */}
                {!editing && invoice.repeat && (
                    <div className={section}>
                        <div className="invoice-detail__schedule">
                            <span className="invoice-detail__schedule-text">
                                Repeats {invoice.repeat === 'yearly' ? 'every year' : 'every month'}
                                {invoice.next_repeat_on && <> &middot; next copy <strong>{formatDate(invoice.next_repeat_on.slice(0, 10))}</strong>, sent at 9:00 AM</>}
                            </span>
                            <div className="invoice-detail__schedule-actions">
                                <Button variant="link-accent" onClick={stopRepeating}>Stop repeating</Button>
                            </div>
                        </div>
                    </div>
                )}
                {!editing && !invoice.repeat && invoice.repeated_from && (
                    <div className={section}>
                        <span className="invoice-detail__schedule-text">
                            A copy of #{invoice.repeated_from.invoice_number}
                            {invoice.repeated_from.repeat ? `, which repeats ${invoice.repeated_from.repeat === 'yearly' ? 'every year' : 'every month'}` : ''}.
                        </span>
                    </div>
                )}

                {pendingScheduledSend && (
                    <div className={section}>
                        <div className="invoice-detail__schedule">
                            <span className="invoice-detail__schedule-text">
                                Scheduled for <strong>{formatDateTimeEastern(pendingScheduledSend.scheduled_for)}</strong>
                            </span>
                            <div className="invoice-detail__schedule-actions">
                                <Button variant="link" onClick={() => sendScheduledNow(pendingScheduledSend)}>Send now</Button>
                                <Button variant="link" onClick={() => startRescheduling(pendingScheduledSend)}>Reschedule</Button>
                                <Button variant="link-accent" onClick={() => cancelScheduledSend(pendingScheduledSend)}>Cancel</Button>
                            </div>
                        </div>
                        {reschedulingId === pendingScheduledSend.id && (
                            <div className="invoice-detail__reschedule">
                                <input type="date" value={rescheduleDate} onChange={(e) => setRescheduleDate(e.target.value)} className="input input--sm" />
                                <input type="time" value={rescheduleTime} onChange={(e) => setRescheduleTime(e.target.value)} className="input input--sm" />
                                <Button variant="confirm" onClick={() => confirmReschedule(pendingScheduledSend)}>Save</Button>
                                <Button variant="secondary" onClick={() => setReschedulingId(null)}>Cancel</Button>
                            </div>
                        )}
                    </div>
                )}

                {invoice.status !== 'draft' && invoice.status !== 'paid' && (
                    <div className={section}>
                        {heading('Reminders')}
                        <ul className="detail-list">
                            {reminderRows(invoice).map((row) => (
                                <li key={row.rule} className="detail-list__item detail-list__item--split">
                                    <span>
                                        {row.label} &middot; {formatDate(row.date.toISOString())}
                                        {row.status === 'sent' && <span className="detail-list__status--success"> &middot; Sent</span>}
                                        {row.status === 'cancelled' && <span className="detail-list__status--muted"> &middot; Skipped</span>}
                                        {row.status === 'failed' && <span className="detail-list__status--error"> &middot; Failed</span>}
                                    </span>
                                    {row.status === 'upcoming' && (
                                        <Button variant="link-accent" onClick={() => skipReminder(row.rule)}>Skip</Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {invoice.invoice_sends?.length > 0 && (
                    <div className={section}>
                        {heading('History')}
                        <ul className="detail-list">
                            {invoice.invoice_sends.map((send) => (
                                <li key={send.id} className="detail-list__item">
                                    {send.type === 'link' && <>Link copied{send.sent_by ? ` by ${send.sent_by.name}` : ''}, {formatDateTimeEastern(send.sent_at)}</>}
                                    {send.type === 'email' && send.status === 'sent' && <>Sent to {(send.recipients || []).join(', ')}, {formatDateTimeEastern(send.sent_at)}</>}
                                    {send.type === 'email' && send.status === 'scheduled' && <>Scheduled for {formatDateTimeEastern(send.scheduled_for)}</>}
                                    {send.type === 'email' && send.status === 'cancelled' && <>Scheduled send cancelled{send.failure_reason ? `: ${send.failure_reason}` : ''}</>}
                                    {send.type === 'email' && send.status === 'failed' && <span className="detail-list__status--error">Send failed{send.failure_reason ? `: ${send.failure_reason}` : ''}</span>}
                                    {send.type === 'reminder' && send.status === 'sent' && <>Reminder sent to {(send.recipients || []).join(', ')}, {formatDateTimeEastern(send.sent_at)}</>}
                                    {send.type === 'reminder' && send.status === 'cancelled' && <>Reminder skipped{send.failure_reason ? `: ${send.failure_reason}` : ''}</>}
                                    {send.type === 'reminder' && send.status === 'failed' && <span className="detail-list__status--error">Reminder failed{send.failure_reason ? `: ${send.failure_reason}` : ''}</span>}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {!editing && error && <div className="form-message form-message--error form-message--spaced">{error}</div>}

                {!editing && invoice.status === 'sent' && (
                    <div className={bare ? 'form-panel' : undefined}>
                        {bare && heading('Record payment')}
                        <div className="invoice-detail__payment">
                            <select
                                value={paymentMethod}
                                onChange={(e) => setPaymentMethod(e.target.value)}
                                className="input input--sm input--inline"
                            >
                                <option value="check">Check</option>
                                <option value="other">Other</option>
                            </select>
                            <Button variant="confirm" onClick={recordPayment} disabled={recordingPayment}>
                                Record payment
                            </Button>
                        </div>
                    </div>
                )}

                {sendModalOpen && (
                    <SendInvoiceModal
                        invoice={invoice}
                        studio={studio}
                        invoicingDefaults={invoicingDefaults}
                        onClose={() => {
                            setSendModalOpen(false);
                            // Not sent: a draft opened for editing goes back to it.
                            if (editsInPlace) startEditing();
                        }}
                        onSent={(updated, message) => {
                            if (!editsInPlace) {
                                handleSent(updated, message);
                                return;
                            }
                            // The drawer closes; once a queued email has
                            // gone out, the page's list catches up again.
                            setSendModalOpen(false);
                            onDone();
                            if (hasQueuedEmail(updated)) {
                                waitForQueuedSend(updated.id).then((latest) => latest && onDone({ close: false }));
                            }
                        }}
                    />
                )}
            </>
        ),
    });
}

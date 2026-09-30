import { useEffect, useMemo, useRef, useState } from 'react';
import { CalendarBlank, Check, Copy, LinkSimple, PaperPlaneTilt, X } from '@phosphor-icons/react';
import Button from './Button';
import Toggle from './Toggle';
import { api } from '../lib/api';
import { copyToClipboard } from '../lib/clipboard';
import { formatCurrency, formatDate } from '../lib/format';
import { easternWallTimeToUtcIso, formatDateTimeEastern, utcToEasternParts } from '../lib/datetime';
import { addDays } from '../lib/scheduleDates';
import { hasQueuedEmail, waitForQueuedSend } from '../lib/invoiceSends';

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function resolveBillingContact(invoice) {
    return invoice.contact
        || invoice.company.contacts.find((c) => c.is_billing)
        || invoice.company.contacts.find((c) => c.is_primary)
        || null;
}

// Every other billing contact with an email -- CC'd by default so a client
// with several billing contacts gets the invoice to all of them. Mirrors
// Invoice::billingCcEmails().
function billingCcEmails(invoice, to) {
    const emails = invoice.company.contacts
        .filter((c) => c.is_billing && c.email && c.email !== to?.email)
        .map((c) => c.email);
    return [...new Set(emails)];
}

function fillTemplate(template, invoice, studio, contact) {
    const firstName = contact?.name?.split(' ')[0] || 'there';

    return template
        .replaceAll(':firm_name', studio.name)
        .replaceAll(':invoice_number', invoice.invoice_number)
        .replaceAll(':amount_due', formatCurrency(invoice.remaining_balance))
        .replaceAll(':due_date', formatDate(invoice.due_on))
        .replaceAll(':contact_first_name', firstName);
}

// A scheduled send defaults to 9:00 AM Eastern: today if that's still
// ahead, otherwise tomorrow.
const DEFAULT_SCHEDULE_TIME = '09:00';

function defaultScheduleParts() {
    const now = utcToEasternParts(new Date().toISOString());
    const date = now.time < DEFAULT_SCHEDULE_TIME ? now.date : addDays(now.date, 1);
    return { date, time: DEFAULT_SCHEDULE_TIME };
}

// Send Invoice modal. Three ways to send, picked from a grid -- Schedule
// and Send via URL side by side, Send Now across the full width below --
// plus automatic reminders as a plain toggle. No modal/dialog component
// exists elsewhere in this app to build on (only a right-edge slide-in
// drawer), so this is a from-scratch centered dialog -- it reuses the
// drawer's fade-in backdrop animation and its manual Escape-listener idiom,
// but adds its own focus trap since nothing else in the app has one.
// `justCreated`: opened straight from a new-invoice form's Send invoice,
// so it says the invoice is already saved (Cancel leaves it a draft).
export default function SendInvoiceModal({ invoice, studio, invoicingDefaults, onClose, onSent, justCreated = false }) {
    const contact = resolveBillingContact(invoice);
    const alreadySent = Boolean(invoice.sent_at);
    const blockingIssues = invoice.send_blocking_issues || [];
    const emailBlocked = blockingIssues.length > 0 || invoice.contact_email_missing;
    const linkBlocked = blockingIssues.length > 0;

    // How it goes out: 'now' (email it now), 'schedule' (email it later) or
    // 'link' (share the URL yourself). The email/link method and now/later
    // timing the API takes both follow from it.
    const [mode, setMode] = useState(emailBlocked && !linkBlocked ? 'link' : 'now');
    const activeTab = mode === 'link' ? 'link' : 'email';
    const sendTiming = mode === 'schedule' ? 'later' : 'now';
    // On by default for every send; turn it off here for this invoice.
    const [remindersEnabled, setRemindersEnabled] = useState(true);
    const initialSchedule = useMemo(defaultScheduleParts, []);
    const [scheduleDate, setScheduleDate] = useState(initialSchedule.date);
    const [scheduleTime, setScheduleTime] = useState(initialSchedule.time);

    const defaultCc = useMemo(() => billingCcEmails(invoice, contact), [invoice, contact]);
    const [ccOpen, setCcOpen] = useState(defaultCc.length > 0);
    const [ccInput, setCcInput] = useState(defaultCc.join(', '));
    const [subject, setSubject] = useState(() => fillTemplate(invoicingDefaults.emailSubjectTemplate, invoice, studio, contact));
    const [message, setMessage] = useState(() => fillTemplate(invoicingDefaults.emailTemplate, invoice, studio, contact));
    const [sendCopyToSelf, setSendCopyToSelf] = useState(false);
    const [markAsSent, setMarkAsSent] = useState(true);
    const [updateIssueDate, setUpdateIssueDate] = useState(true);
    const [copied, setCopied] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewHtml, setPreviewHtml] = useState('');
    const [previewLoading, setPreviewLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');

    const panelRef = useRef(null);
    const firstFieldRef = useRef(null);

    useEffect(() => {
        firstFieldRef.current?.focus();

        function onKeyDown(e) {
            if (e.key === 'Escape') {
                onClose();
                return;
            }

            if (e.key === 'Tab' && panelRef.current) {
                const focusables = Array.from(
                    panelRef.current.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
                ).filter((el) => !el.disabled);

                if (focusables.length === 0) return;

                const first = focusables[0];
                const last = focusables[focusables.length - 1];

                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }

        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [onClose]);

    function ccList() {
        return ccInput.split(',').map((s) => s.trim()).filter(Boolean);
    }

    const ccInvalid = ccList().some((email) => !EMAIL_PATTERN.test(email));

    async function loadPreview() {
        setPreviewLoading(true);
        setError('');
        try {
            const data = await api.post(`/api/invoices/${invoice.id}/email-preview`, { subject, message });
            setPreviewHtml(data.html);
            setPreviewOpen(true);
        } catch (err) {
            setError(err.message);
        } finally {
            setPreviewLoading(false);
        }
    }

    async function submit() {
        if (submitting) return;
        setError('');

        if (activeTab === 'email' && ccInvalid) {
            setError("One of the CC addresses doesn't look like a valid email address.");
            return;
        }

        const scheduledForIso = sendTiming === 'later' ? easternWallTimeToUtcIso(scheduleDate, scheduleTime) : null;

        setSubmitting(true);
        try {
            const payload = activeTab === 'link'
                ? {
                    method: 'link',
                    mark_as_sent: markAsSent,
                    reminders_enabled: remindersEnabled,
                    update_issue_date: updateIssueDate,
                }
                : {
                    method: 'email',
                    subject,
                    message,
                    cc: ccList(),
                    send_copy_to_self: sendCopyToSelf,
                    schedule: sendTiming,
                    scheduled_for: scheduledForIso || undefined,
                    reminders_enabled: remindersEnabled,
                    update_issue_date: updateIssueDate,
                };

            const updated = await api.post(`/api/invoices/${invoice.id}/send`, payload);

            const successMessage = activeTab === 'link'
                ? (markAsSent ? 'Invoice marked as sent. Link ready to share.' : 'Link ready to share.')
                : sendTiming === 'later'
                    ? `Invoice scheduled for ${formatDateTimeEastern(scheduledForIso)}.`
                    : `Invoice sent to ${contact?.email}.`;

            onSent(updated, successMessage);
        } catch (err) {
            setError(err.message);
        } finally {
            setSubmitting(false);
        }
    }

    async function copyLink() {
        const ok = await copyToClipboard(invoice.public_url);
        if (!ok) {
            setError('Could not copy the link. Copy it manually instead.');
            return;
        }
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    }

    const footerLabel = activeTab === 'link' ? 'Done' : sendTiming === 'later' ? 'Schedule Invoice' : 'Send Invoice';
    const footerDisabled = submitting || (activeTab === 'email' ? emailBlocked : linkBlocked);

    return (
        <div className="modal">
            <div className="modal__backdrop" onClick={onClose} />
            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="send-dialog-title"
                className="modal__panel"
            >
                <div className="modal__header">
                    <h2 id="send-dialog-title" className="modal__title">Send Invoice</h2>
                    <button onClick={onClose} className="icon-btn icon-btn--secondary" aria-label="Close">
                        <X />
                    </button>
                </div>

                <div className="modal__body">
                    {justCreated && (
                        <div className="alert alert--info alert--compact">
                            Invoice #{invoice.invoice_number} is saved as a draft. Send it now, or cancel to leave it as a draft.
                        </div>
                    )}

                    {alreadySent && (
                        <div className="alert alert--info alert--compact">
                            This invoice has already been sent. The client won&rsquo;t see changes in their original email until you resend it; the online link always shows the latest version.
                        </div>
                    )}

                    {blockingIssues.length > 0 && (
                        <div className="alert alert--danger">
                            {blockingIssues.join(' ')}
                        </div>
                    )}

                    {invoice.needs_issue_date_update && blockingIssues.length === 0 && (
                        <div className="alert alert--info alert--compact">
                            <Toggle
                                checked={updateIssueDate}
                                onChange={setUpdateIssueDate}
                                label={`The issue date is in the past. Update it to ${sendTiming === 'later' ? 'the scheduled send date' : 'today'} and recalculate the due date?`}
                            />
                        </div>
                    )}

                    <div role="tablist" aria-label="How to send" className="send-dialog__methods">
                        {[
                            { value: 'schedule', label: 'Schedule', icon: <CalendarBlank />, disabled: emailBlocked },
                            { value: 'link', label: 'Send via URL', icon: <LinkSimple />, disabled: linkBlocked },
                            { value: 'now', label: 'Send Now', icon: <PaperPlaneTilt />, disabled: emailBlocked, wide: true },
                        ].map((option) => (
                            <button
                                key={option.value}
                                ref={option.value === 'now' ? firstFieldRef : undefined}
                                type="button"
                                role="tab"
                                aria-selected={mode === option.value}
                                onClick={() => setMode(option.value)}
                                disabled={option.disabled}
                                className={`send-dialog__method${option.wide ? ' send-dialog__method--wide' : ''}${mode === option.value ? ' send-dialog__method--active' : ''}`}
                            >
                                {option.icon} {option.label}
                            </button>
                        ))}
                    </div>

                    <div className="send-dialog__settings">
                        {mode === 'schedule' && (
                            <div>
                                <label className="label">Send on</label>
                                <div className="send-dialog__schedule">
                                    <input type="date" value={scheduleDate} onChange={(e) => setScheduleDate(e.target.value)} className="input" />
                                    <input type="time" value={scheduleTime} onChange={(e) => setScheduleTime(e.target.value)} className="input" />
                                </div>
                                {/* Times are Eastern (America/New_York); the note saying so is hidden for now. */}
                            </div>
                        )}
                        <Toggle checked={remindersEnabled} onChange={setRemindersEnabled} label="Automatic payment reminders" />
                    </div>

                    {activeTab === 'email' ? (
                        <div role="tabpanel" className="send-dialog__panel">
                            <div>
                                <label className="label">To</label>
                                <input
                                    value={contact?.email || 'No contact email -- update the contact on the invoice'}
                                    readOnly
                                    className="input"
                                />
                            </div>

                            {!ccOpen ? (
                                <button type="button" onClick={() => setCcOpen(true)} className="send-dialog__text-action">
                                    + Add CC
                                </button>
                            ) : (
                                <div>
                                    <label className="label">CC</label>
                                    <input
                                        value={ccInput}
                                        onChange={(e) => setCcInput(e.target.value)}
                                        placeholder="comma-separated email addresses"
                                        className="input"
                                    />
                                    {ccInvalid && <p className="form-error">One of these doesn&rsquo;t look like a valid email address.</p>}
                                </div>
                            )}

                            <div>
                                <label className="label">Subject</label>
                                <input value={subject} onChange={(e) => setSubject(e.target.value)} className="input" />
                            </div>

                            <div>
                                <label className="label">Message</label>
                                <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={5} className="input" />
                            </div>

                            <div className="send-dialog__option-row">
                                <Toggle checked={sendCopyToSelf} onChange={setSendCopyToSelf} label="Send me a copy" />
                                <Button type="button" variant="secondary" className="btn--sm" onClick={loadPreview} disabled={previewLoading}>
                                    {previewLoading ? 'Loading preview…' : 'Show email preview'}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div role="tabpanel" className="send-dialog__panel">
                            <div>
                                <label className="label">Client link</label>
                                <div className="send-dialog__link-row">
                                    <input value={invoice.public_url} readOnly className="input send-dialog__link-input" />
                                    <Button type="button" variant="secondary" onClick={copyLink}>
                                        {copied ? <Check size={14} /> : <Copy size={14} />}
                                        <span className="send-dialog__copy-label">{copied ? 'Copied' : 'Copy link'}</span>
                                    </Button>
                                </div>
                                <span className="u-sr-only" role="status" aria-live="polite">{copied ? 'Link copied to clipboard' : ''}</span>
                            </div>

                            <div className="send-dialog__option-row">
                                <Toggle checked={markAsSent} onChange={setMarkAsSent} label="Mark as sent" />
                                <a href={invoice.public_url} target="_blank" rel="noopener noreferrer" title="View it as the client will see it" className="btn btn--secondary btn--sm">
                                    Open client view
                                </a>
                            </div>
                        </div>
                    )}

                    {error && <div className="send-dialog__error">{error}</div>}
                </div>

                <div className="modal__footer">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="button" variant="primary" onClick={submit} disabled={footerDisabled}>
                        {submitting ? 'Sending…' : footerLabel}
                    </Button>
                </div>
            </div>

            {previewOpen && (
                <div className="modal modal--nested">
                    <div className="modal__backdrop" onClick={() => setPreviewOpen(false)} />
                    <div className="modal__panel modal__panel--frame">
                        <div className="modal__header">
                            <span className="modal__title">Email preview</span>
                            <button onClick={() => setPreviewOpen(false)} className="icon-btn icon-btn--secondary" aria-label="Close preview">
                                <X />
                            </button>
                        </div>
                        <iframe title="Email preview" srcDoc={previewHtml} className="modal__frame" />
                    </div>
                </div>
            )}
        </div>
    );
}

// Open this dialog for an invoice from outside its detail view -- a new
// invoice straight from its creation form, or a draft's row on a list:
// `openFor(id)` loads it and opens the dialog. `justCreated` (the default)
// adds the "saved as a draft" note; a list row passes false.
// `onDone(sent)` runs when the dialog closes -- sent, or cancelled with
// the invoice left a draft. An email goes out on the queue, so `refresh`
// runs again once it has, to pick up the Sent status. Render `modal`
// inside the form's own frame (a drawer keeps its Escape for the dialog
// while one is inside it).
export function useSendAfterCreate(onDone, refresh) {
    const [detail, setDetail] = useState(null); // the /api/invoices/{id} payload
    const [justCreated, setJustCreated] = useState(true);

    async function openFor(invoiceId, { justCreated: created = true } = {}) {
        setJustCreated(created);
        setDetail(await api.get(`/api/invoices/${invoiceId}`));
    }

    function finish(sent, updated) {
        setDetail(null);
        onDone(sent);
        if (sent && hasQueuedEmail(updated)) {
            waitForQueuedSend(updated.id).then((latest) => latest && refresh?.());
        }
    }

    const modal = detail && (
        <SendInvoiceModal
            invoice={detail.invoice}
            studio={detail.studio}
            invoicingDefaults={detail.invoicingDefaults}
            justCreated={justCreated}
            onClose={() => finish(false)}
            onSent={(updated) => finish(true, updated)}
        />
    );

    return { openFor, modal };
}

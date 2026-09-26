import { useEffect, useMemo, useRef, useState } from 'react';
import { CalendarBlank, Check, Copy, Envelope, Lightning, LinkSimple, X } from '@phosphor-icons/react';
import Button from './Button';
import Toggle from './Toggle';
import { api } from '../lib/api';
import { copyToClipboard } from '../lib/clipboard';
import { formatCurrency, formatDate } from '../lib/format';
import { easternWallTimeToUtcIso, formatDateTimeEastern, utcToEasternParts } from '../lib/datetime';

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

function defaultScheduleParts() {
    return utcToEasternParts(new Date(Date.now() + 60 * 60000).toISOString());
}

// Bonsai-style Send Invoice modal: Send Email / Send via URL tabs, optional
// scheduling, optional automatic reminders. No modal/dialog component
// exists elsewhere in this app to build on (only a right-edge slide-in
// drawer), so this is a from-scratch centered dialog -- it reuses the
// drawer's fade-in backdrop animation and its manual Escape-listener idiom,
// but adds its own focus trap since nothing else in the app has one.
export default function SendInvoiceModal({ invoice, studio, invoicingDefaults, onClose, onSent }) {
    const contact = resolveBillingContact(invoice);
    const alreadySent = Boolean(invoice.sent_at);
    const blockingIssues = invoice.send_blocking_issues || [];
    const emailBlocked = blockingIssues.length > 0 || invoice.contact_email_missing;
    const linkBlocked = blockingIssues.length > 0;

    const [activeTab, setActiveTab] = useState(emailBlocked && !linkBlocked ? 'link' : 'email');
    const [remindersOpen, setRemindersOpen] = useState(false);
    const [remindersEnabled, setRemindersEnabled] = useState(invoice.effective_reminders_enabled);
    const [timingOpen, setTimingOpen] = useState(false);
    const [sendTiming, setSendTiming] = useState('now');
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

    function closeDropdowns() {
        setRemindersOpen(false);
        setTimingOpen(false);
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
                aria-labelledby="send-invoice-title"
                className="modal__panel"
            >
                <div className="modal__header">
                    <h2 id="send-invoice-title" className="modal__title">Send Invoice</h2>
                    <button onClick={onClose} className="icon-btn icon-btn--secondary" aria-label="Close">
                        <X />
                    </button>
                </div>

                <div className="modal__body" onClick={closeDropdowns}>
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
                        <label className="alert alert--info alert--compact alert--choice">
                            <input
                                type="checkbox"
                                checked={updateIssueDate}
                                onChange={(e) => setUpdateIssueDate(e.target.checked)}
                                className="alert__control"
                            />
                            <span>
                                The issue date is in the past. Update it to {sendTiming === 'later' ? 'the scheduled send date' : 'today'} and recalculate the due date?
                            </span>
                        </label>
                    )}

                    <div role="tablist" aria-label="Send method" className="send-invoice__methods">
                        <button
                            ref={firstFieldRef}
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'email'}
                            onClick={() => setActiveTab('email')}
                            disabled={emailBlocked}
                            className={`send-invoice__method${activeTab === 'email' ? ' send-invoice__method--active' : ''}`}
                        >
                            <Envelope /> Send Email
                        </button>
                        <button
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'link'}
                            onClick={() => setActiveTab('link')}
                            disabled={linkBlocked}
                            className={`send-invoice__method${activeTab === 'link' ? ' send-invoice__method--active' : ''}`}
                        >
                            <LinkSimple /> Send via URL
                        </button>
                    </div>

                    <div className="send-invoice__options">
                        <div className="send-invoice__option" onClick={(e) => e.stopPropagation()}>
                            <button
                                type="button"
                                onClick={() => setRemindersOpen((v) => !v)}
                                className="input input--sm send-invoice__trigger"
                            >
                                <Lightning size={14} />
                                {remindersEnabled ? 'Automatic reminders enabled' : 'Automatic reminders off'}
                            </button>
                            {remindersOpen && (
                                <div className="popover popover--padded send-invoice__popover">
                                    <Toggle checked={remindersEnabled} onChange={setRemindersEnabled} label="Send automatic payment reminders" />
                                    <p className="send-invoice__hint">
                                        Uses the client&rsquo;s reminder setting (or the firm default) unless overridden here.
                                    </p>
                                </div>
                            )}
                        </div>

                        {activeTab === 'email' && (
                            <div className="send-invoice__option" onClick={(e) => e.stopPropagation()}>
                                <button
                                    type="button"
                                    onClick={() => setTimingOpen((v) => !v)}
                                    className="input input--sm send-invoice__trigger"
                                >
                                    <CalendarBlank size={14} />
                                    {sendTiming === 'later' ? 'Scheduled' : 'Send immediately'}
                                </button>
                                {timingOpen && (
                                    <div className="popover popover--padded send-invoice__popover send-invoice__popover--wide">
                                        <label className="choice">
                                            <input type="radio" checked={sendTiming === 'now'} onChange={() => setSendTiming('now')} />
                                            Send immediately
                                        </label>
                                        <label className="choice">
                                            <input type="radio" checked={sendTiming === 'later'} onChange={() => setSendTiming('later')} />
                                            Schedule
                                        </label>
                                        {sendTiming === 'later' && (
                                            <>
                                                <div className="send-invoice__schedule">
                                                    <input type="date" value={scheduleDate} onChange={(e) => setScheduleDate(e.target.value)} className="input input--sm" />
                                                    <input type="time" value={scheduleTime} onChange={(e) => setScheduleTime(e.target.value)} className="input input--sm" />
                                                </div>
                                                <p className="send-invoice__hint">Times are in Eastern (America/New_York).</p>
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {activeTab === 'email' ? (
                        <div role="tabpanel" className="send-invoice__panel">
                            <div>
                                <label className="label">To</label>
                                <input
                                    value={contact?.email || 'No contact email -- update the contact on the invoice'}
                                    readOnly
                                    className="input"
                                />
                            </div>

                            {!ccOpen ? (
                                <button type="button" onClick={() => setCcOpen(true)} className="send-invoice__text-action">
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

                            <label className="choice">
                                <input type="checkbox" checked={sendCopyToSelf} onChange={(e) => setSendCopyToSelf(e.target.checked)} />
                                Send me a copy
                            </label>

                            <button type="button" onClick={loadPreview} disabled={previewLoading} className="send-invoice__text-action">
                                {previewLoading ? 'Loading preview…' : 'Show Email Preview'}
                            </button>
                        </div>
                    ) : (
                        <div role="tabpanel" className="send-invoice__panel">
                            <div>
                                <label className="label">Client link</label>
                                <div className="send-invoice__link-row">
                                    <input value={invoice.public_url} readOnly className="input send-invoice__link-input" />
                                    <Button type="button" variant="secondary" onClick={copyLink}>
                                        {copied ? <Check size={14} /> : <Copy size={14} />}
                                        <span className="send-invoice__copy-label">{copied ? 'Copied' : 'Copy link'}</span>
                                    </Button>
                                </div>
                                <span className="u-sr-only" role="status" aria-live="polite">{copied ? 'Link copied to clipboard' : ''}</span>
                            </div>

                            <label className="choice">
                                <input type="checkbox" checked={markAsSent} onChange={(e) => setMarkAsSent(e.target.checked)} />
                                Mark as sent
                            </label>

                            <a href={invoice.public_url} target="_blank" rel="noopener noreferrer" className="send-invoice__text-action">
                                Open link (view as the client will see it)
                            </a>
                        </div>
                    )}

                    {error && <div className="send-invoice__error">{error}</div>}
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

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

    const [ccOpen, setCcOpen] = useState(false);
    const [ccInput, setCcInput] = useState('');
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
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-gunmetal/20 drawer-overlay" onClick={onClose} />
            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="send-invoice-title"
                className="relative w-full max-w-lg bg-white rounded-lg shadow-xl drawer-panel max-h-[90vh] overflow-y-auto"
            >
                <div className="flex items-center justify-between px-6 py-4 border-b border-border">
                    <h2 id="send-invoice-title" className="font-display text-lg font-semibold">Send Invoice</h2>
                    <button onClick={onClose} className="icon-btn icon-btn-secondary" aria-label="Close">
                        <X />
                    </button>
                </div>

                <div className="px-6 py-4" onClick={closeDropdowns}>
                    {alreadySent && (
                        <div className="text-xs text-shadow-grey bg-porcelain rounded px-3 py-2 mb-4">
                            This invoice has already been sent. The client won&rsquo;t see changes in their original email until you resend it; the online link always shows the latest version.
                        </div>
                    )}

                    {blockingIssues.length > 0 && (
                        <div className="text-sm text-watermelon bg-watermelon-soft rounded px-3 py-2 mb-4">
                            {blockingIssues.join(' ')}
                        </div>
                    )}

                    {invoice.needs_issue_date_update && blockingIssues.length === 0 && (
                        <label className="flex items-start gap-2 text-xs text-shadow-grey bg-porcelain rounded px-3 py-2 mb-4">
                            <input
                                type="checkbox"
                                checked={updateIssueDate}
                                onChange={(e) => setUpdateIssueDate(e.target.checked)}
                                className="mt-0.5"
                            />
                            <span>
                                The issue date is in the past. Update it to {sendTiming === 'later' ? 'the scheduled send date' : 'today'} and recalculate the due date?
                            </span>
                        </label>
                    )}

                    <div role="tablist" aria-label="Send method" className="grid grid-cols-2 gap-2 mb-4">
                        <button
                            ref={firstFieldRef}
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'email'}
                            onClick={() => setActiveTab('email')}
                            disabled={emailBlocked}
                            className={`flex items-center justify-center gap-2 rounded border px-3 py-3 text-sm font-semibold transition-colors disabled:opacity-40 disabled:cursor-not-allowed ${
                                activeTab === 'email' ? 'border-watermelon text-watermelon' : 'border-border text-shadow-grey'
                            }`}
                        >
                            <Envelope /> Send Email
                        </button>
                        <button
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'link'}
                            onClick={() => setActiveTab('link')}
                            disabled={linkBlocked}
                            className={`flex items-center justify-center gap-2 rounded border px-3 py-3 text-sm font-semibold transition-colors disabled:opacity-40 disabled:cursor-not-allowed ${
                                activeTab === 'link' ? 'border-watermelon text-watermelon' : 'border-border text-shadow-grey'
                            }`}
                        >
                            <LinkSimple /> Send via URL
                        </button>
                    </div>

                    <div className="flex items-center gap-2 mb-4">
                        <div className="relative" onClick={(e) => e.stopPropagation()}>
                            <button
                                type="button"
                                onClick={() => setRemindersOpen((v) => !v)}
                                className="field field-sm w-auto flex items-center gap-1.5"
                            >
                                <Lightning size={14} />
                                {remindersEnabled ? 'Automatic reminders enabled' : 'Automatic reminders off'}
                            </button>
                            {remindersOpen && (
                                <div className="absolute z-10 mt-1 w-64 bg-white border border-border rounded shadow-lg p-3">
                                    <Toggle checked={remindersEnabled} onChange={setRemindersEnabled} label="Send automatic payment reminders" />
                                    <p className="text-xs text-shadow-grey mt-2">
                                        Uses the client&rsquo;s reminder setting (or the firm default) unless overridden here.
                                    </p>
                                </div>
                            )}
                        </div>

                        {activeTab === 'email' && (
                            <div className="relative" onClick={(e) => e.stopPropagation()}>
                                <button
                                    type="button"
                                    onClick={() => setTimingOpen((v) => !v)}
                                    className="field field-sm w-auto flex items-center gap-1.5"
                                >
                                    <CalendarBlank size={14} />
                                    {sendTiming === 'later' ? 'Scheduled' : 'Send immediately'}
                                </button>
                                {timingOpen && (
                                    <div className="absolute z-10 mt-1 w-72 bg-white border border-border rounded shadow-lg p-3 space-y-2">
                                        <label className="flex items-center gap-2 text-sm">
                                            <input type="radio" checked={sendTiming === 'now'} onChange={() => setSendTiming('now')} />
                                            Send immediately
                                        </label>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input type="radio" checked={sendTiming === 'later'} onChange={() => setSendTiming('later')} />
                                            Schedule
                                        </label>
                                        {sendTiming === 'later' && (
                                            <>
                                                <div className="flex gap-2 pt-1">
                                                    <input type="date" value={scheduleDate} onChange={(e) => setScheduleDate(e.target.value)} className="field field-sm" />
                                                    <input type="time" value={scheduleTime} onChange={(e) => setScheduleTime(e.target.value)} className="field field-sm" />
                                                </div>
                                                <p className="text-xs text-shadow-grey">Times are in Eastern (America/New_York).</p>
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {activeTab === 'email' ? (
                        <div role="tabpanel" className="space-y-3">
                            <div>
                                <label className="field-label">To</label>
                                <input
                                    value={contact?.email || 'No contact email -- update the contact on the invoice'}
                                    readOnly
                                    className="field"
                                />
                            </div>

                            {!ccOpen ? (
                                <button type="button" onClick={() => setCcOpen(true)} className="text-xs text-watermelon hover:underline">
                                    + Add CC
                                </button>
                            ) : (
                                <div>
                                    <label className="field-label">CC</label>
                                    <input
                                        value={ccInput}
                                        onChange={(e) => setCcInput(e.target.value)}
                                        placeholder="comma-separated email addresses"
                                        className="field"
                                    />
                                    {ccInvalid && <p className="text-xs text-watermelon mt-1">One of these doesn&rsquo;t look like a valid email address.</p>}
                                </div>
                            )}

                            <div>
                                <label className="field-label">Subject</label>
                                <input value={subject} onChange={(e) => setSubject(e.target.value)} className="field" />
                            </div>

                            <div>
                                <label className="field-label">Message</label>
                                <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={5} className="field" />
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={sendCopyToSelf} onChange={(e) => setSendCopyToSelf(e.target.checked)} />
                                Send me a copy
                            </label>

                            <button type="button" onClick={loadPreview} disabled={previewLoading} className="text-xs text-watermelon hover:underline">
                                {previewLoading ? 'Loading preview…' : 'Show Email Preview'}
                            </button>
                        </div>
                    ) : (
                        <div role="tabpanel" className="space-y-3">
                            <div>
                                <label className="field-label">Client link</label>
                                <div className="flex gap-2">
                                    <input value={invoice.public_url} readOnly className="field flex-1" />
                                    <Button type="button" variant="secondary" onClick={copyLink}>
                                        {copied ? <Check size={14} /> : <Copy size={14} />}
                                        <span className="ml-1">{copied ? 'Copied' : 'Copy link'}</span>
                                    </Button>
                                </div>
                                <span className="sr-only" role="status" aria-live="polite">{copied ? 'Link copied to clipboard' : ''}</span>
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={markAsSent} onChange={(e) => setMarkAsSent(e.target.checked)} />
                                Mark as sent
                            </label>

                            <a href={invoice.public_url} target="_blank" rel="noopener noreferrer" className="text-xs text-watermelon hover:underline inline-block">
                                Open link (view as the client will see it)
                            </a>
                        </div>
                    )}

                    {error && <div className="text-sm text-watermelon mt-3">{error}</div>}
                </div>

                <div className="flex justify-end gap-2 px-6 py-4 border-t border-border">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="button" variant="primary" onClick={submit} disabled={footerDisabled}>
                        {submitting ? 'Sending…' : footerLabel}
                    </Button>
                </div>
            </div>

            {previewOpen && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-gunmetal/40" onClick={() => setPreviewOpen(false)} />
                    <div className="relative w-full max-w-2xl h-[80vh] bg-white rounded-lg shadow-xl flex flex-col">
                        <div className="flex items-center justify-between px-4 py-3 border-b border-border">
                            <span className="text-sm font-semibold">Email preview</span>
                            <button onClick={() => setPreviewOpen(false)} className="icon-btn icon-btn-secondary" aria-label="Close preview">
                                <X />
                            </button>
                        </div>
                        <iframe title="Email preview" srcDoc={previewHtml} className="flex-1 w-full" />
                    </div>
                </div>
            )}
        </div>
    );
}

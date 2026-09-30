import { useEffect, useRef, useState } from 'react';
import { Check, Copy, LinkSimple, PaperPlaneTilt, X } from '@phosphor-icons/react';
import Button from './Button';
import Toggle from './Toggle';
import { api } from '../lib/api';
import { copyToClipboard } from '../lib/clipboard';

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Send Proposal dialog -- the Send Invoice dialog's layout without the
// scheduling: Send now (email it to the client, with CC, a copy to you and
// a preview) or Send via URL (copy the link and send it from your own email
// app, optionally marking it sent). It opens with the recipient and the
// subject and message filled in from the server (/send-context).
// `onSent(updated)` runs once it's gone (or been marked sent).
export default function SendProposalModal({ proposal, onClose, onSent }) {
    const [context, setContext] = useState(null); // { to, to_name, subject, message, public_url }
    const [mode, setMode] = useState('now'); // 'now' (email) | 'link'
    const [ccOpen, setCcOpen] = useState(false);
    const [ccInput, setCcInput] = useState('');
    const [subject, setSubject] = useState('');
    const [message, setMessage] = useState('');
    const [sendCopyToSelf, setSendCopyToSelf] = useState(false);
    const [markAsSent, setMarkAsSent] = useState(proposal.status === 'draft');
    const [copied, setCopied] = useState(false);
    const [previewHtml, setPreviewHtml] = useState('');
    const [previewLoading, setPreviewLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');
    const panelRef = useRef(null);
    const firstFieldRef = useRef(null);

    useEffect(() => {
        api.get(`/api/proposals/${proposal.id}/send-context`)
            .then((data) => {
                setContext(data);
                setSubject(data.subject);
                setMessage(data.message);
                // No one to email: open on the link instead.
                if (!data.to) setMode('link');
            })
            .catch((err) => setError(err.message || 'Could not load this proposal.'));
    }, [proposal.id]);

    // Escape closes; Tab stays inside the dialog (as in SendInvoiceModal).
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
    }, [onClose, context]);

    const ccList = () => ccInput.split(',').map((s) => s.trim()).filter(Boolean);
    const ccInvalid = ccList().some((email) => !EMAIL_PATTERN.test(email));

    async function loadPreview() {
        setPreviewLoading(true);
        setError('');
        try {
            const data = await api.post(`/api/proposals/${proposal.id}/email-preview`, { subject, message });
            setPreviewHtml(data.html);
        } catch (err) {
            setError(err.message);
        } finally {
            setPreviewLoading(false);
        }
    }

    async function copyLink() {
        const ok = await copyToClipboard(context.public_url);
        if (!ok) {
            setError('Could not copy the link. Copy it manually instead.');
            return;
        }
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    }

    async function submit() {
        if (submitting) return;
        setError('');
        if (mode === 'now' && ccInvalid) {
            setError("One of the CC addresses doesn't look like a valid email address.");
            return;
        }
        setSubmitting(true);
        try {
            const payload = mode === 'link'
                ? { method: 'link', mark_as_sent: markAsSent }
                : { method: 'email', subject, message, cc: ccList(), send_copy_to_self: sendCopyToSelf };
            const updated = await api.post(`/api/proposals/${proposal.id}/send`, payload);
            onSent(updated);
        } catch (err) {
            setError(err.message);
        } finally {
            setSubmitting(false);
        }
    }

    const options = [
        { value: 'now', label: 'Send now', icon: <PaperPlaneTilt />, disabled: !context?.to },
        { value: 'link', label: 'Send via URL', icon: <LinkSimple />, disabled: false },
    ];

    return (
        <div className="modal">
            <div className="modal__backdrop" onClick={onClose} />
            <div ref={panelRef} role="dialog" aria-modal="true" aria-labelledby="send-proposal-title" className="modal__panel">
                <div className="modal__header">
                    <h2 id="send-proposal-title" className="modal__title">Send Proposal</h2>
                    <button onClick={onClose} className="icon-btn icon-btn--secondary" aria-label="Close">
                        <X />
                    </button>
                </div>

                <div className="modal__body">
                    {!context ? (
                        error ? <div className="alert alert--danger">{error}</div> : <p className="form-hint">Loading…</p>
                    ) : (
                        <>
                            {!context.to && (
                                <div className="alert alert--info alert--compact">
                                    This client has no contact with an email address, so it can only be sent via its link. Add an email to a contact to send it from here.
                                </div>
                            )}

                            <div role="tablist" aria-label="How to send" className="send-dialog__methods">
                                {options.map((option, idx) => (
                                    <button
                                        key={option.value}
                                        ref={idx === 0 ? firstFieldRef : undefined}
                                        type="button"
                                        role="tab"
                                        aria-selected={mode === option.value}
                                        onClick={() => setMode(option.value)}
                                        disabled={option.disabled}
                                        className={`send-dialog__method${mode === option.value ? ' send-dialog__method--active' : ''}`}
                                    >
                                        {option.icon} {option.label}
                                    </button>
                                ))}
                            </div>

                            {mode === 'now' ? (
                                <div role="tabpanel" className="send-dialog__panel">
                                    <div>
                                        <label className="label">To</label>
                                        <input value={context.to_name ? `${context.to_name} <${context.to}>` : context.to} readOnly className="input" />
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
                                        <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={6} className="input" />
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
                                        <label className="label">Proposal link</label>
                                        <div className="send-dialog__link-row">
                                            <input value={context.public_url} readOnly className="input send-dialog__link-input" />
                                            <Button type="button" variant="secondary" onClick={copyLink}>
                                                {copied ? <Check size={14} /> : <Copy size={14} />}
                                                <span className="send-dialog__copy-label">{copied ? 'Copied' : 'Copy link'}</span>
                                            </Button>
                                        </div>
                                        <span className="u-sr-only" role="status" aria-live="polite">{copied ? 'Link copied to clipboard' : ''}</span>
                                    </div>

                                    <div className="send-dialog__option-row">
                                        {proposal.status === 'accepted' ? (
                                            <span className="form-hint">Already accepted — sharing the link again changes nothing.</span>
                                        ) : (
                                            <Toggle checked={markAsSent} onChange={setMarkAsSent} label="Mark as sent" />
                                        )}
                                        <a href={context.public_url} target="_blank" rel="noopener noreferrer" title="View it as the client will see it" className="btn btn--secondary btn--sm">
                                            Open client view
                                        </a>
                                    </div>
                                </div>
                            )}

                            {error && <div className="send-dialog__error">{error}</div>}
                        </>
                    )}
                </div>

                <div className="modal__footer">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="button" variant="primary" onClick={submit} disabled={!context || submitting}>
                        {submitting ? 'Sending…' : mode === 'link' ? 'Done' : 'Send proposal'}
                    </Button>
                </div>
            </div>

            {previewHtml && (
                <div className="modal modal--nested">
                    <div className="modal__backdrop" onClick={() => setPreviewHtml('')} />
                    <div className="modal__panel modal__panel--frame">
                        <div className="modal__header">
                            <span className="modal__title">Email preview</span>
                            <button onClick={() => setPreviewHtml('')} className="icon-btn icon-btn--secondary" aria-label="Close preview">
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

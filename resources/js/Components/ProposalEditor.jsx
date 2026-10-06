import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import AutoResizeTextarea from './AutoResizeTextarea';
import CurrencyInput from './CurrencyInput';
import { ArrowDown, ArrowUp, Check, Copy, DotsSixVertical, Eye, X } from '@phosphor-icons/react';
import Avatar from './Avatar';
import Toggle from './Toggle';
import Button from './Button';
import RichTextEditor from './RichTextEditor';
import RichTextView from './RichTextView';
import SendProposalModal from './SendProposalModal';
import { formatCurrency } from '../lib/format';
import { api } from '../lib/api';
import { copyToClipboard } from '../lib/clipboard';
import { isBlankRichText, toPlainText, toRichText } from '../lib/richText';

// The proposal editor, shared by the standalone page (Pages/Proposals/Form)
// and the project page's proposal drawer. The caller supplies the frame
// (page card or drawer) and decides what happens after a save or cancel.

const NEW_PROJECT = '__new__';

// A new proposal whose client is already set (started from a project or
// a client) addresses that client's primary contact, as picking the client
// from the list does.
function emptyForm(proposal, presetCompanyId, presetProjectId, companies = [], defaultDisclaimer = '') {
    if (!proposal) {
        const presetCompany = presetCompanyId ? companies.find((c) => String(c.id) === String(presetCompanyId)) : null;
        const primaryContact = presetCompany?.contacts?.find((c) => c.is_primary);
        return {
            company_id: presetCompanyId ? String(presetCompanyId) : '',
            contact_id: primaryContact ? String(primaryContact.id) : '',
            project_id: presetProjectId ? String(presetProjectId) : '',
            new_project_name: '',
            title: '',
            body: '',
            disclaimer: defaultDisclaimer,
            team_user_ids: [],
            team_heading: '',
            show_about: true,
            items: [],
        };
    }
    return {
        company_id: String(proposal.company_id),
        contact_id: proposal.contact_id ? String(proposal.contact_id) : '',
        project_id: proposal.project_id ? String(proposal.project_id) : '',
        new_project_name: '',
        title: proposal.title,
        body: proposal.body,
        disclaimer: proposal.disclaimer ?? '',
        team_user_ids: proposal.team_user_ids ?? [],
        team_heading: proposal.team_heading ?? '',
        show_about: proposal.show_about ?? true,
        items: proposal.items.map((item) => ({
            service_id: item.service_id ? String(item.service_id) : '',
            description: item.description,
            details: toRichText(item.details),
            quantity: item.quantity,
            rate: item.rate,
        })),
    };
}

function emptyItem() {
    return { service_id: '', description: '', details: '', quantity: 1, rate: '' };
}

function lineAmount(item) {
    return (parseFloat(item.quantity) || 0) * (parseFloat(item.rate) || 0);
}

// `companies` needs each company's contacts and projects. A preset
// project (arriving from a project) pins both the client and the project
// so the proposal can't end up attached elsewhere. `onSaved` runs after a
// successful save; `onCancel` backs out.
// `onSaved` runs after a save or a send (the caller closes and refreshes);
// `onChange` after an unaccept, which leaves the editor open.
// The proposal's team section: pick staff to show with their photo,
// position and bio (kept on each person's record -- Team page, or their
// profile), in the order they'll appear, under an optional heading.
function TeamPanel({ ids, heading, onChange }) {
    const [options, setOptions] = useState([]);

    useEffect(() => {
        api.get('/api/proposal-team').then(setOptions).catch(() => setOptions([]));
    }, []);

    const byId = Object.fromEntries(options.map((o) => [o.id, o]));
    const chosen = ids.map((id) => byId[id]).filter(Boolean);
    const available = options.filter((o) => !ids.includes(o.id));

    function move(index, by) {
        const next = [...ids];
        [next[index], next[index + by]] = [next[index + by], next[index]];
        onChange({ team_user_ids: next });
    }

    return (
        <div className="form-panel">
            <div className="section-label section-label--ruled">Team</div>
            {chosen.length > 0 && (
                <>
                    <input
                        placeholder="Your team"
                        value={heading}
                        onChange={(e) => onChange({ team_heading: e.target.value })}
                        aria-label="Team section heading"
                        className="input proposal-form__field"
                    />
                    <div className="proposal-team">
                        {chosen.map((person, index) => (
                            <div key={person.id} className="proposal-team__person">
                                <Avatar name={person.name} avatarUrl={person.photo_url} id={person.id} size={32} />
                                <div className="proposal-team__who">
                                    <div className="proposal-team__name">{person.name}</div>
                                    <div className="proposal-team__note">
                                        {[person.job_title || 'No position yet', !person.photo_url && 'no bio photo yet', !person.has_bio && 'no bio yet'].filter(Boolean).join(' · ')}
                                    </div>
                                </div>
                                <button type="button" onClick={() => move(index, -1)} disabled={index === 0} title="Move up" aria-label={`Move ${person.name} up`} className="icon-btn">
                                    <ArrowUp />
                                </button>
                                <button type="button" onClick={() => move(index, 1)} disabled={index === chosen.length - 1} title="Move down" aria-label={`Move ${person.name} down`} className="icon-btn">
                                    <ArrowDown />
                                </button>
                                <button type="button" onClick={() => onChange({ team_user_ids: ids.filter((id) => id !== person.id) })} title="Remove" aria-label={`Remove ${person.name}`} className="icon-btn icon-btn--danger">
                                    <X />
                                </button>
                            </div>
                        ))}
                    </div>
                </>
            )}
            {available.length > 0 && (
                <select
                    value=""
                    onChange={(e) => e.target.value && onChange({ team_user_ids: [...ids, Number(e.target.value)] })}
                    aria-label="Add someone to the team section"
                    className="input"
                >
                    <option value="">{chosen.length ? 'Add someone else…' : 'Add team members…'}</option>
                    {available.map((o) => <option key={o.id} value={o.id}>{o.name}{o.job_title ? ` — ${o.job_title}` : ''}</option>)}
                </select>
            )}
            <p className="form-hint form-hint--attached">
                Shown to the client after the disclaimer, before the estimate: each person&rsquo;s bio photo, position and bio from their Team record. Leave it empty for no team section.
            </p>
        </div>
    );
}

export default function ProposalEditor({ proposal, companies, services, presetCompanyId, presetProjectId, onSaved, onCancel, onChange = onSaved }) {
    const isEditing = !!proposal;
    // New proposals start with the studio's default disclaimer (shared by
    // the server from config/proposals.php).
    const defaultDisclaimer = usePage().props.proposalDefaults?.disclaimer ?? '';
    const [form, setForm] = useState(() => emptyForm(proposal, presetCompanyId, presetProjectId, companies, defaultDisclaimer));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [copied, setCopied] = useState(false);
    const [unaccepting, setUnaccepting] = useState(false);
    // The saved proposal the Send dialog is open for (Send saves first).
    const [sendTarget, setSendTarget] = useState(null);
    const [dragIndex, setDragIndex] = useState(null);
    // A line item is only draggable while its handle is held: a draggable
    // ancestor stops the details editor (contenteditable) from selecting
    // text with the mouse.
    const [armedIndex, setArmedIndex] = useState(null);

    const itemsTotal = form.items.reduce((s, i) => s + lineAmount(i), 0);
    const selectedCompany = companies.find((c) => String(c.id) === String(form.company_id));
    const contactsForCompany = selectedCompany?.contacts || [];
    const projectsForCompany = selectedCompany?.projects || [];
    const contextLocked = !isEditing && !!presetProjectId;
    // Fixed (shown, not picked) wherever they're already decided: the client
    // when editing, or when started from a project or a client's page (the
    // editor then gets just that one client); the project when editing or
    // started from a project.
    const companyFixed = isEditing || (!!presetCompanyId && (contextLocked || companies.length === 1));
    const projectFixed = isEditing || contextLocked;
    // Accepted: the services (and so the estimate) are locked -- the
    // estimate went into the project's budget on accept, and comes back off
    // on unaccept. The rest of the proposal can still change.
    const servicesLocked = proposal?.status === 'accepted';
    const fixedProjectName = isEditing
        ? proposal.project?.name
        : projectsForCompany.find((p) => String(p.id) === String(presetProjectId))?.name;

    function handleCompanyChange(value) {
        const company = companies.find((c) => String(c.id) === String(value));
        const primaryContact = company?.contacts?.find((c) => c.is_primary);
        setForm({
            ...form,
            company_id: value,
            contact_id: primaryContact ? String(primaryContact.id) : '',
            project_id: '',
            new_project_name: '',
        });
    }

    function handleProjectChange(value) {
        if (value === NEW_PROJECT) {
            setForm({ ...form, project_id: '', new_project_name: form.new_project_name || '' });
        } else {
            setForm({ ...form, project_id: value, new_project_name: '' });
        }
    }

    // A line for a flat-fee service: one price, so no quantity to enter.
    // Only at quantity 1, so a line saved with more (before the service was
    // flat) still shows what it's multiplied by.
    function isFlatFee(item) {
        const service = services.find((s) => String(s.id) === String(item.service_id));
        return service?.unit === 'fixed' && Number(item.quantity) === 1;
    }

    function updateItem(idx, field, value) {
        const items = form.items.map((item, i) => {
            if (i !== idx) return item;
            const next = { ...item, [field]: value };
            if (field === 'service_id' && value) {
                const service = services.find((s) => String(s.id) === String(value));
                if (service) {
                    next.description = service.name;
                    next.details = isBlankRichText(next.details) ? toRichText(service.description) : next.details;
                    next.rate = service.default_rate;
                    // A flat fee is one price, not hours times a rate.
                    if (service.unit === 'fixed') next.quantity = 1;
                }
            }
            // A custom item has no service name, so its details double as
            // its name -- as plain text, since the name can't hold formatting.
            if (field === 'service_id' && !value) {
                next.description = toPlainText(next.details);
            }
            if (field === 'details' && !next.service_id) {
                next.description = toPlainText(value);
            }
            return next;
        });
        setForm({ ...form, items });
    }
    function addItem() {
        setForm({ ...form, items: [...form.items, emptyItem()] });
    }
    function removeItem(idx) {
        setForm({ ...form, items: form.items.filter((_, i) => i !== idx) });
    }
    function handleItemDrop(fromIndex, toIndex) {
        if (fromIndex === toIndex) return;
        const items = [...form.items];
        const [moved] = items.splice(fromIndex, 1);
        items.splice(toIndex, 0, moved);
        setForm({ ...form, items });
    }

    // Saves the form and resolves with the saved proposal, or null when a
    // required field is missing or the save fails (the error is shown).
    async function persist() {
        // Name exactly what's missing. An emptied scope editor still holds an
        // empty paragraph, so it counts as blank too.
        const missing = [
            !form.company_id && 'a client',
            !form.title.trim() && 'a title',
            isBlankRichText(form.body) && 'the scope of work',
        ].filter(Boolean);
        if (missing.length > 0) {
            setError(`Add ${missing.length > 1 ? `${missing.slice(0, -1).join(', ')} and ${missing.at(-1)}` : missing[0]} before saving.`);
            return null;
        }
        if (!isEditing && !form.project_id && !form.new_project_name.trim()) {
            setError('Pick a project for this proposal, or name a new one to create.');
            return null;
        }
        const validItems = form.items
            .filter((i) => i.description.trim() && parseFloat(i.rate) >= 0)
            .map((i) => ({ ...i, details: isBlankRichText(i.details) ? null : i.details }));
        setSaving(true);
        setError('');
        try {
            const payload = {
                title: form.title,
                body: form.body,
                // Blank means none.
                disclaimer: form.disclaimer.trim() || null,
                team_user_ids: form.team_user_ids,
                team_heading: form.team_heading.trim() || null,
                show_about: form.show_about,
                contact_id: form.contact_id || null,
                // The estimate is the services' total, worked out on save.
                // Left out when locked, so the server keeps them as they are.
                ...(servicesLocked ? {} : { items: validItems }),
            };
            if (!isEditing) {
                payload.project_id = form.project_id || null;
                payload.new_project_name = form.project_id ? null : form.new_project_name.trim();
            }
            return isEditing
                ? await api.patch(`/api/proposals/${proposal.id}`, payload)
                : await api.post(`/api/companies/${form.company_id}/proposals`, payload);
        } catch (err) {
            setError(err.message);
            return null;
        } finally {
            setSaving(false);
        }
    }

    async function save() {
        if (await persist()) onSaved();
    }

    // Saves any changes first, so what's sent is what's on screen, then
    // opens the Send dialog. However the dialog ends, the proposal is saved.
    async function saveAndSend() {
        const saved = await persist();
        if (saved) setSendTarget(saved);
    }

    function closeSendDialog() {
        setSendTarget(null);
        onSaved();
    }

    async function copyLink() {
        const ok = await copyToClipboard(`${window.location.origin}/p/${proposal.accept_token}`);
        if (!ok) {
            setError('Could not copy the link. Copy it manually instead.');
            return;
        }
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    }

    async function unaccept() {
        if (!confirm('Revert this proposal to sent? The client will be able to accept it again, and its estimate comes off the project budget.')) return;
        setUnaccepting(true);
        try {
            await api.post(`/api/proposals/${proposal.id}/unaccept`);
            onChange();
        } finally {
            setUnaccepting(false);
        }
    }

    return (
        <div className="proposal-form">
            {/* Grouped on panels like the invoice forms: who and what for, the
                proposal itself, then its services. */}
            <div className="form-panel">
                <div className="section-label section-label--ruled">Client, project &amp; contact</div>
                {/* Client, project and contact side by side. */}
                <div className="form-grid form-grid--3">
                    <div>
                        <label className="label">Client</label>
                        {companyFixed ? (
                            <div className="proposal-form__fixed">{selectedCompany?.name ?? '—'}</div>
                        ) : (
                            <select
                                value={form.company_id}
                                onChange={(e) => handleCompanyChange(e.target.value)}
                                className="input"
                            >
                                <option value="">Select client</option>
                                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        )}
                    </div>
                    <div>
                        <label className="label">Project</label>
                        {projectFixed ? (
                            <div className="proposal-form__fixed">{fixedProjectName ?? '—'}</div>
                        ) : (
                            <>
                                <select
                                    value={form.company_id ? (form.project_id || NEW_PROJECT) : ''}
                                    disabled={!form.company_id}
                                    onChange={(e) => handleProjectChange(e.target.value)}
                                    className="input"
                                >
                                    {!form.company_id ? (
                                        <option value="">Select a client first</option>
                                    ) : (
                                        <>
                                            {projectsForCompany.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                                            <option value={NEW_PROJECT}>+ New project</option>
                                        </>
                                    )}
                                </select>
                                {/* Naming a new project, under its picker. */}
                                {!form.project_id && (
                                    <input
                                        placeholder="New project name"
                                        value={form.new_project_name}
                                        disabled={!form.company_id}
                                        onChange={(e) => setForm({ ...form, new_project_name: e.target.value })}
                                        className="input proposal-form__new-project"
                                    />
                                )}
                            </>
                        )}
                    </div>
                    <div>
                        <label className="label">Contact</label>
                        <select
                            value={form.contact_id}
                            disabled={!form.company_id}
                            onChange={(e) => setForm({ ...form, contact_id: e.target.value })}
                            className="input"
                        >
                            <option value="">
                                {form.company_id ? 'Send to (no specific contact)' : 'Select a client first'}
                            </option>
                            {contactsForCompany.map((contact) => (
                                <option key={contact.id} value={contact.id}>
                                    {contact.name}{contact.email ? ` (${contact.email})` : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>
            <div className="form-panel">
                <div className="section-label section-label--ruled">Proposal</div>
                <input
                    placeholder="Proposal title"
                    value={form.title}
                    onChange={(e) => setForm({ ...form, title: e.target.value })}
                    className="input proposal-form__field"
                />
                <RichTextEditor value={form.body} onChange={(body) => setForm({ ...form, body })} />
            </div>

            {/* Shown to the client between the scope of work and the services. */}
            <div className="form-panel">
                <div className="section-label section-label--ruled">Disclaimer</div>
                <AutoResizeTextarea
                    value={form.disclaimer}
                    onChange={(e) => setForm({ ...form, disclaimer: e.target.value })}
                    placeholder="No disclaimer on this proposal"
                    rows={3}
                    className="input"
                />
                <p className="form-hint form-hint--attached">Shown to the client below the scope of work, above the services. Leave it blank for none.</p>
            </div>

            <TeamPanel
                ids={form.team_user_ids}
                heading={form.team_heading}
                onChange={(changes) => setForm({ ...form, ...changes })}
            />

            {/* The studio's About, closing the proposal -- its text is in Settings. */}
            <div className="form-panel">
                <div className="section-label section-label--ruled">About</div>
                <Toggle checked={form.show_about} onChange={(show_about) => setForm({ ...form, show_about })} label="Close with the About section" />
                <p className="form-hint form-hint--attached">The studio&rsquo;s About, at the end of the proposal after the team. Its text is in Settings &rsaquo; Proposals.</p>
            </div>

            <div className="form-panel">
                <div className="section-label section-label--ruled">Services</div>
                {servicesLocked ? (
                    <>
                        <p className="form-hint proposal-form__locked-note">
                            This proposal is accepted, so its services are locked. Unaccept it to change them.
                        </p>
                        {form.items.map((item, idx) => (
                            <div key={idx} className="proposal-form__item proposal-form__item--locked">
                                <div className="proposal-form__item-body">
                                    <div className="proposal-form__item-fields">
                                        <div className="proposal-form__service proposal-form__cell">
                                            {services.find((svc) => String(svc.id) === String(item.service_id))?.name ?? item.description}
                                        </div>
                                        <div className="proposal-form__qty proposal-form__cell">
                                            {isFlatFee(item) ? 'Flat fee' : item.quantity}
                                        </div>
                                        <div className="proposal-form__rate proposal-form__cell">{formatCurrency(item.rate)}</div>
                                        <div className="proposal-form__line-total">{formatCurrency(lineAmount(item))}</div>
                                    </div>
                                    {!isBlankRichText(item.details) && (
                                        <div className="proposal-form__item-notes">
                                            <div className="proposal-form__details">
                                                <RichTextView value={item.details} />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        ))}
                    </>
                ) : (
                    <>
                    {form.items.map((item, idx) => (
                        <div
                            key={idx}
                            draggable={armedIndex === idx}
                            onDragStart={() => setDragIndex(idx)}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={() => {
                                handleItemDrop(dragIndex, idx);
                                setDragIndex(null);
                            }}
                            onDragEnd={() => {
                                setDragIndex(null);
                                setArmedIndex(null);
                            }}
                            className={`proposal-form__item${dragIndex === idx ? ' proposal-form__item--dragging' : ''}`}
                        >
                            <div
                                className="proposal-form__handle"
                                title="Drag to reorder"
                                onPointerDown={() => setArmedIndex(idx)}
                                onPointerUp={() => setArmedIndex(null)}
                            >
                                <DotsSixVertical size={14} weight="bold" />
                            </div>
                            <div className="proposal-form__item-body">
                                <div className="proposal-form__item-fields">
                                    <select
                                        value={item.service_id}
                                        onChange={(e) => updateItem(idx, 'service_id', e.target.value)}
                                        className="input input--xs proposal-form__service"
                                    >
                                        <option value="">Custom</option>
                                        {services.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                    </select>
                                    {isFlatFee(item) ? (
                                        <div className="proposal-form__qty proposal-form__cell proposal-form__cell--muted">Flat fee</div>
                                    ) : (
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.25"
                                            placeholder="Qty"
                                            value={item.quantity}
                                            onChange={(e) => updateItem(idx, 'quantity', e.target.value)}
                                            className="input input--xs proposal-form__qty"
                                        />
                                    )}
                                    <CurrencyInput
                                        placeholder={isFlatFee(item) ? 'Fee' : 'Rate'}
                                        value={item.rate}
                                        onChange={(value) => updateItem(idx, 'rate', value)}
                                        className="input input--xs proposal-form__rate"
                                    />
                                    <div className="proposal-form__line-total">
                                        {formatCurrency(lineAmount(item))}
                                    </div>
                                </div>
                                <div className="proposal-form__item-notes">
                                    <div className="proposal-form__details">
                                        <RichTextEditor
                                            compact
                                            value={item.details}
                                            onChange={(details) => updateItem(idx, 'details', details)}
                                        />
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => removeItem(idx)}
                                    className="proposal-form__remove"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    ))}
                    <Button variant="link-accent" onClick={addItem}>+ Add line item</Button>
                    </>
                )}

            </div>

            {/* The estimate: never entered by hand, always what the services
                add up to (and locked with them once accepted). */}
            <div className="form-panel proposal-form__estimate">
                <div className="section-label section-label--ruled">Estimate</div>
                <div className="proposal-form__estimate-row">
                    <span className="proposal-form__estimate-note">
                        {form.items.length > 0 ? 'Total of the services above' : 'Add services above to build the estimate'}
                    </span>
                    <span className="proposal-form__estimate-value u-tabular-nums">{formatCurrency(itemsTotal)}</span>
                </div>
            </div>

            {error && <div className="form-message form-message--error form-message--spaced">{error}</div>}

            {/* Every action in one place, at the end: the proposal's own tools
                on the left (once it exists), saving and sending on the right. */}
            <div className="proposal-form__toolbar">
                {proposal && (
                    <div className="proposal-form__tools">
                        <a href={`/p/${proposal.accept_token}`} target="_blank" rel="noopener noreferrer" title="Preview as the client sees it" aria-label="Preview" className="icon-btn icon-btn--secondary">
                            <Eye />
                        </a>
                        <button type="button" onClick={copyLink} title={copied ? 'Copied!' : 'Copy link'} aria-label="Copy link" className="icon-btn icon-btn--secondary">
                            {copied ? <Check /> : <Copy />}
                        </button>
                        {proposal.status === 'accepted' && (
                            <Button variant="link-accent" disabled={unaccepting} onClick={unaccept}>
                                {unaccepting ? 'Reverting…' : 'Unaccept'}
                            </Button>
                        )}
                    </div>
                )}
                <div className="form-actions">
                    <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
                    {servicesLocked ? (
                        <Button type="button" variant="confirm" disabled={saving} onClick={save}>Save changes</Button>
                    ) : (
                        <>
                            <Button type="button" variant="secondary" disabled={saving} onClick={save}>
                                {isEditing ? 'Save changes' : 'Save draft'}
                            </Button>
                            <Button type="button" variant="confirm" disabled={saving} onClick={saveAndSend}>
                                {proposal?.status === 'sent' ? 'Resend proposal' : 'Send proposal'}
                            </Button>
                        </>
                    )}
                </div>
            </div>

            {sendTarget && <SendProposalModal proposal={sendTarget} onClose={closeSendDialog} onSent={closeSendDialog} />}
        </div>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, Check, Copy, DotsSixVertical, Eye } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import RichTextEditor from '../../Components/RichTextEditor';
import { ProposalStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency } from '../../lib/format';
import { api } from '../../lib/api';
import { copyToClipboard } from '../../lib/clipboard';

const NEW_PROJECT = '__new__';

function emptyForm(proposal, presetCompanyId, presetProjectId) {
    if (!proposal) {
        return {
            company_id: presetCompanyId ? String(presetCompanyId) : '',
            contact_id: '',
            project_id: presetProjectId ? String(presetProjectId) : '',
            new_project_name: '',
            title: '',
            body: '',
            estimate_amount: '',
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
        estimate_amount: proposal.estimate_amount ?? '',
        items: proposal.items.map((item) => ({
            service_id: item.service_id ? String(item.service_id) : '',
            description: item.description,
            details: item.details ?? '',
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

export default function ProposalsForm({ proposal, companies, services, presetCompanyId, presetProjectId }) {
    const isEditing = !!proposal;
    const [form, setForm] = useState(emptyForm(proposal, presetCompanyId, presetProjectId));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [dragIndex, setDragIndex] = useState(null);
    const [sending, setSending] = useState(false);
    const [copied, setCopied] = useState(false);
    const [unaccepting, setUnaccepting] = useState(false);

    const itemsTotal = form.items.reduce((s, i) => s + lineAmount(i), 0);
    const selectedCompany = companies.find((c) => String(c.id) === String(form.company_id));
    const contactsForCompany = selectedCompany?.contacts || [];
    const projectsForCompany = selectedCompany?.projects || [];
    // Arriving from a project's detail page pins both the client and the
    // project so the proposal can't accidentally end up attached elsewhere.
    const contextLocked = !isEditing && !!presetProjectId;

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

    function updateItem(idx, field, value) {
        const items = form.items.map((item, i) => {
            if (i !== idx) return item;
            const next = { ...item, [field]: value };
            if (field === 'service_id' && value) {
                const service = services.find((s) => String(s.id) === String(value));
                if (service) {
                    next.description = service.name;
                    next.details = next.details || service.description || '';
                    next.rate = service.default_rate;
                }
            }
            if (field === 'service_id' && !value) {
                next.description = next.details;
            }
            if (field === 'details' && !next.service_id) {
                next.description = value;
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

    async function save() {
        if (!form.company_id || !form.title || !form.body) {
            setError('Client, title, and scope of work are all required.');
            return;
        }
        if (!isEditing && !form.project_id && !form.new_project_name.trim()) {
            setError('Pick a project for this proposal, or name a new one to create.');
            return;
        }
        const validItems = form.items.filter((i) => i.description.trim() && parseFloat(i.rate) >= 0);
        setSaving(true);
        setError('');
        try {
            const payload = {
                title: form.title,
                body: form.body,
                contact_id: form.contact_id || null,
                estimate_amount: validItems.length > 0 ? undefined : (form.estimate_amount || null),
                items: validItems.length > 0 ? validItems : undefined,
            };
            if (!isEditing) {
                payload.project_id = form.project_id || null;
                payload.new_project_name = form.project_id ? null : form.new_project_name.trim();
            }
            if (isEditing) {
                await api.patch(`/api/proposals/${proposal.id}`, payload);
            } else {
                await api.post(`/api/companies/${form.company_id}/proposals`, payload);
            }
            router.visit('/proposals');
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    function acceptLink() {
        return `${window.location.origin}/p/${proposal.accept_token}`;
    }

    async function sendProposal() {
        setSending(true);
        try {
            await api.post(`/api/proposals/${proposal.id}/send`);
            router.reload();
        } finally {
            setSending(false);
        }
    }

    async function copyLink() {
        const ok = await copyToClipboard(acceptLink());
        if (!ok) {
            alert('Could not copy the link. Copy it manually instead.');
            return;
        }
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    }

    async function unacceptProposal() {
        if (!confirm('Revert this proposal to sent? The client will be able to accept it again.')) return;
        setUnaccepting(true);
        try {
            await api.post(`/api/proposals/${proposal.id}/unaccept`);
            router.reload();
        } finally {
            setUnaccepting(false);
        }
    }

    return (
        <AppLayout>
            <Head title={isEditing ? `Edit — ${proposal.title}` : 'New proposal'} />
            <div className="max-w-3xl">
            <div className="mb-1">
                <Link href="/proposals" className="text-sm text-shadow-grey hover:underline inline-flex items-center gap-1">
                    <ArrowLeft size={14} /> Proposals
                </Link>
            </div>
            <div className="flex items-center justify-between mb-6">
                <h1 className="font-display text-2xl font-semibold">{isEditing ? 'Edit proposal' : 'New proposal'}</h1>
                {isEditing && (
                    <div className="flex items-center gap-3">
                        <ProposalStatusBadge proposal={proposal} />
                        <a href={`/p/${proposal.accept_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="icon-btn icon-btn-secondary">
                            <Eye size={20} />
                        </a>
                        {proposal.status === 'draft' ? (
                            <Button variant="link-accent" disabled={sending} onClick={sendProposal}>
                                {sending ? 'Sending…' : 'Send'}
                            </Button>
                        ) : (
                            <button type="button" onClick={copyLink} title={copied ? 'Copied!' : 'Copy link'} className="icon-btn icon-btn-secondary">
                                {copied ? <Check size={20} /> : <Copy size={20} />}
                            </button>
                        )}
                        {proposal.status === 'accepted' && (
                            <Button variant="link-danger" disabled={unaccepting} onClick={unacceptProposal}>
                                {unaccepting ? 'Reverting…' : 'Unaccept'}
                            </Button>
                        )}
                    </div>
                )}
            </div>

            <div className="card card-padded">
                <div className="grid grid-cols-2 gap-3 mb-3">
                    <select
                        value={form.company_id}
                        disabled={isEditing || contextLocked}
                        onChange={(e) => handleCompanyChange(e.target.value)}
                        className="field"
                    >
                        <option value="">Select client</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <select
                        value={form.contact_id}
                        disabled={!form.company_id}
                        onChange={(e) => setForm({ ...form, contact_id: e.target.value })}
                        className="field"
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
                <div className="mb-3">
                    {isEditing ? (
                        <div className="field field-static">
                            Project: {proposal.project?.name ?? '—'}
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-3">
                            <select
                                value={form.company_id ? (form.project_id || NEW_PROJECT) : ''}
                                disabled={!form.company_id || contextLocked}
                                onChange={(e) => handleProjectChange(e.target.value)}
                                className="field"
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
                            {!form.project_id && (
                                <input
                                    placeholder="New project name"
                                    value={form.new_project_name}
                                    disabled={!form.company_id}
                                    onChange={(e) => setForm({ ...form, new_project_name: e.target.value })}
                                    className="field"
                                />
                            )}
                        </div>
                    )}
                </div>
                <div className="mb-3">
                    {form.items.length === 0 ? (
                        <input
                            type="number"
                            min="0"
                            placeholder="Estimate amount ($)"
                            value={form.estimate_amount}
                            onChange={(e) => setForm({ ...form, estimate_amount: e.target.value })}
                            className="field tabular-nums"
                        />
                    ) : (
                        <div className="field field-static tabular-nums">
                            Estimate: {formatCurrency(itemsTotal)} (from line items)
                        </div>
                    )}
                </div>
                <input
                    placeholder="Proposal title"
                    value={form.title}
                    onChange={(e) => setForm({ ...form, title: e.target.value })}
                    className="field mb-3"
                />
                <div className="mb-4">
                    <RichTextEditor value={form.body} onChange={(body) => setForm({ ...form, body })} />
                </div>

                <div className="mb-4">
                    <div className="text-xs font-semibold text-shadow-grey mb-2">Services</div>
                    {form.items.map((item, idx) => (
                        <div
                            key={idx}
                            draggable
                            onDragStart={() => setDragIndex(idx)}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={() => {
                                handleItemDrop(dragIndex, idx);
                                setDragIndex(null);
                            }}
                            onDragEnd={() => setDragIndex(null)}
                            className={`flex gap-2 mb-3 pb-3 border-b border-border last:border-b-0 ${
                                dragIndex === idx ? 'opacity-40' : ''
                            }`}
                        >
                            <div
                                className="pt-3 text-shadow-grey cursor-grab"
                                title="Drag to reorder"
                            >
                                <DotsSixVertical size={14} weight="bold" />
                            </div>
                            <div className="flex-1 min-w-0">
                                <div className="grid grid-cols-12 gap-2 mb-2">
                                    <select
                                        value={item.service_id}
                                        onChange={(e) => updateItem(idx, 'service_id', e.target.value)}
                                        className="field field-xs h-9 col-span-5"
                                    >
                                        <option value="">Custom</option>
                                        {services.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                    </select>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.25"
                                        placeholder="Qty"
                                        value={item.quantity}
                                        onChange={(e) => updateItem(idx, 'quantity', e.target.value)}
                                        className="field field-xs h-9 tabular-nums col-span-2"
                                    />
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="Rate"
                                        value={item.rate}
                                        onChange={(e) => updateItem(idx, 'rate', e.target.value)}
                                        className="field field-xs h-9 tabular-nums col-span-2"
                                    />
                                    <div className="col-span-3 h-9 flex items-center justify-end text-sm tabular-nums">
                                        {formatCurrency(lineAmount(item))}
                                    </div>
                                </div>
                                <div className="grid grid-cols-12 gap-2 mb-1">
                                    <textarea
                                        placeholder="Description shown to the client"
                                        value={item.details}
                                        onChange={(e) => updateItem(idx, 'details', e.target.value)}
                                        rows={2}
                                        className="field field-xs text-xs text-shadow-grey col-span-9"
                                    />
                                </div>
                                <button
                                    type="button"
                                    onClick={() => removeItem(idx)}
                                    className="text-xs text-fuchsia"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    ))}
                    <Button variant="link-accent" onClick={addItem}>+ Add line item</Button>

                    {form.items.length > 0 && (
                        <div className="flex justify-end mt-3 pt-3 border-t border-border">
                            <div className="text-sm font-semibold">
                                Total: <span className="tabular-nums">{formatCurrency(itemsTotal)}</span>
                            </div>
                        </div>
                    )}
                </div>

                {error && <div className="text-sm mb-3 text-fuchsia">{error}</div>}

                <div className="flex gap-2 justify-end">
                    <Link href="/proposals" className="btn btn-secondary">Cancel</Link>
                    <Button type="button" variant="confirm" disabled={saving} onClick={save}>
                        {isEditing ? 'Save changes' : 'Save draft'}
                    </Button>
                </div>
            </div>
            </div>
        </AppLayout>
    );
}

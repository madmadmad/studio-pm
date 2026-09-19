import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, DotsSixVertical } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import RichTextEditor from '../../Components/RichTextEditor';
import { formatCurrency } from '../../lib/format';
import { api } from '../../lib/api';

function emptyForm(proposal) {
    if (!proposal) {
        return { company_id: '', contact_id: '', title: '', body: '', estimate_amount: '', items: [] };
    }
    return {
        company_id: String(proposal.company_id),
        contact_id: proposal.contact_id ? String(proposal.contact_id) : '',
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

export default function ProposalsForm({ proposal, companies, services }) {
    const isEditing = !!proposal;
    const [form, setForm] = useState(emptyForm(proposal));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [dragIndex, setDragIndex] = useState(null);

    const itemsTotal = form.items.reduce((s, i) => s + lineAmount(i), 0);
    const contactsForCompany = companies.find((c) => String(c.id) === String(form.company_id))?.contacts || [];

    function handleCompanyChange(value) {
        const company = companies.find((c) => String(c.id) === String(value));
        const primaryContact = company?.contacts?.find((c) => c.is_primary);
        setForm({ ...form, company_id: value, contact_id: primaryContact ? String(primaryContact.id) : '' });
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

    return (
        <AppLayout>
            <Head title={isEditing ? `Edit — ${proposal.title}` : 'New proposal'} />
            <div className="mb-1">
                <Link href="/proposals" className="text-sm text-sage hover:underline inline-flex items-center gap-1">
                    <ArrowLeft size={14} /> Proposals
                </Link>
            </div>
            <h1 className="font-display text-2xl font-semibold mb-6">{isEditing ? 'Edit proposal' : 'New proposal'}</h1>

            <div className="bg-white rounded-lg border border-border p-4">
                <div className="grid grid-cols-2 gap-3 mb-3">
                    <select
                        value={form.company_id}
                        disabled={isEditing}
                        onChange={(e) => handleCompanyChange(e.target.value)}
                        className="border border-border rounded px-3 py-2 text-sm disabled:bg-paper disabled:text-sage"
                    >
                        <option value="">Select client</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <select
                        value={form.contact_id}
                        disabled={!form.company_id}
                        onChange={(e) => setForm({ ...form, contact_id: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm disabled:bg-paper disabled:text-sage"
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
                    {form.items.length === 0 ? (
                        <input
                            type="number"
                            min="0"
                            placeholder="Estimate amount ($)"
                            value={form.estimate_amount}
                            onChange={(e) => setForm({ ...form, estimate_amount: e.target.value })}
                            className="border border-border rounded px-3 py-2 text-sm tabular-nums w-full"
                        />
                    ) : (
                        <div className="border border-border rounded px-3 py-2 text-sm tabular-nums bg-paper text-sage">
                            Estimate: {formatCurrency(itemsTotal)} (from line items)
                        </div>
                    )}
                </div>
                <input
                    placeholder="Proposal title"
                    value={form.title}
                    onChange={(e) => setForm({ ...form, title: e.target.value })}
                    className="border border-border rounded px-3 py-2 text-sm w-full mb-3"
                />
                <div className="mb-4">
                    <RichTextEditor value={form.body} onChange={(body) => setForm({ ...form, body })} />
                </div>

                <div className="mb-4">
                    <div className="text-xs font-semibold text-sage mb-2">Services</div>
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
                                className="pt-3 text-sage cursor-grab"
                                title="Drag to reorder"
                            >
                                <DotsSixVertical size={14} weight="bold" />
                            </div>
                            <div className="flex-1 min-w-0">
                                <div className="grid grid-cols-12 gap-2 mb-2">
                                    <select
                                        value={item.service_id}
                                        onChange={(e) => updateItem(idx, 'service_id', e.target.value)}
                                        className="col-span-5 h-9 border border-border rounded px-2 text-sm"
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
                                        className="col-span-2 h-9 border border-border rounded px-2 text-sm tabular-nums"
                                    />
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="Rate"
                                        value={item.rate}
                                        onChange={(e) => updateItem(idx, 'rate', e.target.value)}
                                        className="col-span-2 h-9 border border-border rounded px-2 text-sm tabular-nums"
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
                                        className="col-span-9 border border-border rounded px-2 py-2 text-xs text-sage"
                                    />
                                </div>
                                <button
                                    type="button"
                                    onClick={() => removeItem(idx)}
                                    className="text-xs text-brick"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    ))}
                    <button type="button" onClick={addItem} className="text-sm font-medium text-brass">+ Add line item</button>

                    {form.items.length > 0 && (
                        <div className="flex justify-end mt-3 pt-3 border-t border-border">
                            <div className="text-sm font-semibold">
                                Total: <span className="tabular-nums">{formatCurrency(itemsTotal)}</span>
                            </div>
                        </div>
                    )}
                </div>

                {error && <div className="text-sm mb-3 text-brick">{error}</div>}

                <div className="flex gap-2 justify-end">
                    <Link href="/proposals" className="text-sm px-3 py-1.5 rounded text-sage">Cancel</Link>
                    <button type="button" disabled={saving} onClick={save} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">
                        {isEditing ? 'Save changes' : 'Save draft'}
                    </button>
                </div>
            </div>
        </AppLayout>
    );
}

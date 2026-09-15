import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { ProposalStatusBadge } from '../../Components/StatusBadges';
import RichTextEditor from '../../Components/RichTextEditor';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

function emptyForm() {
    return { company_id: '', title: '', body: '', estimate_amount: '', items: [] };
}

function emptyItem() {
    return { service_id: '', description: '', quantity: 1, rate: '' };
}

function lineAmount(item) {
    return (parseFloat(item.quantity) || 0) * (parseFloat(item.rate) || 0);
}

export default function ProposalsIndex({ proposals, companies, services }) {
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(emptyForm());
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const itemsTotal = form.items.reduce((s, i) => s + lineAmount(i), 0);

    function updateItem(idx, field, value) {
        const items = form.items.map((item, i) => {
            if (i !== idx) return item;
            const next = { ...item, [field]: value };
            if (field === 'service_id' && value) {
                const service = services.find((s) => String(s.id) === String(value));
                if (service) {
                    next.description = next.description || service.name;
                    next.rate = service.default_rate;
                }
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

    async function saveProposal() {
        if (!form.company_id || !form.title || !form.body) {
            setError('Client, title, and scope of work are all required.');
            return;
        }
        const validItems = form.items.filter((i) => i.description.trim() && parseFloat(i.rate) >= 0);
        setSaving(true);
        setError('');
        try {
            await api.post(`/api/companies/${form.company_id}/proposals`, {
                title: form.title,
                body: form.body,
                estimate_amount: validItems.length > 0 ? undefined : (form.estimate_amount || null),
                items: validItems.length > 0 ? validItems : undefined,
            });
            setForm(emptyForm());
            setShowForm(false);
            router.reload({ only: ['proposals'] });
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    async function sendProposal(proposal) {
        await api.post(`/api/proposals/${proposal.id}/send`);
        router.reload({ only: ['proposals'] });
    }

    function acceptLink(proposal) {
        return `${window.location.origin}/p/${proposal.accept_token}`;
    }

    return (
        <AppLayout>
            <Head title="Proposals" />
            <div className="flex items-center justify-between mb-1">
                <h1 className="text-2xl font-semibold">Proposals</h1>
                <button onClick={() => setShowForm(true)} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">
                    New proposal
                </button>
            </div>
            <p className="text-sm text-sage mb-6">{proposals.length} proposal{proposals.length !== 1 ? 's' : ''} on file.</p>

            {showForm && (
                <div className="bg-white rounded-lg border border-border p-4 mb-6">
                    <div className="grid grid-cols-2 gap-3 mb-3">
                        <select
                            value={form.company_id}
                            onChange={(e) => setForm({ ...form, company_id: e.target.value })}
                            className="border border-border rounded px-3 py-2 text-sm"
                        >
                            <option value="">Select client</option>
                            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        {form.items.length === 0 ? (
                            <input
                                type="number"
                                min="0"
                                placeholder="Estimate amount ($)"
                                value={form.estimate_amount}
                                onChange={(e) => setForm({ ...form, estimate_amount: e.target.value })}
                                className="border border-border rounded px-3 py-2 text-sm font-mono"
                            />
                        ) : (
                            <div className="border border-border rounded px-3 py-2 text-sm font-mono bg-paper text-sage">
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
                        <div className="text-xs font-semibold text-sage mb-2 uppercase tracking-wide">Services</div>
                        {form.items.map((item, idx) => (
                            <div key={idx} className="grid grid-cols-12 gap-2 mb-2 items-start">
                                <select
                                    value={item.service_id}
                                    onChange={(e) => updateItem(idx, 'service_id', e.target.value)}
                                    className="col-span-3 border border-border rounded px-2 py-2 text-xs"
                                >
                                    <option value="">Custom</option>
                                    {services.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </select>
                                <input
                                    placeholder="Description"
                                    value={item.description}
                                    onChange={(e) => updateItem(idx, 'description', e.target.value)}
                                    className="col-span-4 border border-border rounded px-2 py-2 text-sm"
                                />
                                <input
                                    type="number"
                                    min="0"
                                    step="0.25"
                                    placeholder="Qty"
                                    value={item.quantity}
                                    onChange={(e) => updateItem(idx, 'quantity', e.target.value)}
                                    className="col-span-2 border border-border rounded px-2 py-2 text-sm font-mono"
                                />
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="Rate"
                                    value={item.rate}
                                    onChange={(e) => updateItem(idx, 'rate', e.target.value)}
                                    className="col-span-2 border border-border rounded px-2 py-2 text-sm font-mono"
                                />
                                <div className="col-span-1 flex items-center justify-end gap-1 h-full pt-2 text-sm font-mono">
                                    {formatCurrency(lineAmount(item))}
                                </div>
                                <button
                                    type="button"
                                    onClick={() => removeItem(idx)}
                                    className="col-span-12 text-xs text-brick text-right -mt-1"
                                >
                                    Remove
                                </button>
                            </div>
                        ))}
                        <button type="button" onClick={addItem} className="text-sm font-medium text-brass">+ Add line item</button>

                        {form.items.length > 0 && (
                            <div className="flex justify-end mt-3 pt-3 border-t border-border">
                                <div className="text-sm font-semibold">
                                    Total: <span className="font-mono">{formatCurrency(itemsTotal)}</span>
                                </div>
                            </div>
                        )}
                    </div>

                    {error && <div className="text-sm mb-3 text-brick">{error}</div>}

                    <div className="flex gap-2 justify-end">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 rounded text-sage">Cancel</button>
                        <button type="button" disabled={saving} onClick={saveProposal} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Save draft</button>
                    </div>
                </div>
            )}

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {proposals.length === 0 ? (
                    <EmptyState text="No proposals yet." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Client</th>
                                <th className="px-4 py-2 font-medium">Title</th>
                                <th className="px-4 py-2 font-medium">Estimate</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                                <th className="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {proposals.map((proposal) => (
                                <tr key={proposal.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-3 font-medium">{proposal.company.name}</td>
                                    <td className="px-4 py-3">{proposal.title}</td>
                                    <td className="px-4 py-3 font-mono">
                                        {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                                    </td>
                                    <td className="px-4 py-3"><ProposalStatusBadge proposal={proposal} /></td>
                                    <td className="px-4 py-3 text-right">
                                        {proposal.status === 'draft' && (
                                            <button onClick={() => sendProposal(proposal)} className="text-sm font-medium text-brass">Send</button>
                                        )}
                                        {proposal.status === 'sent' && (
                                            <button
                                                onClick={() => navigator.clipboard.writeText(acceptLink(proposal))}
                                                className="text-sm font-medium text-sage"
                                            >
                                                Copy link
                                            </button>
                                        )}
                                        {proposal.status === 'accepted' && (
                                            <span className="text-xs text-sage">{formatDate(proposal.accepted_at)}</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}

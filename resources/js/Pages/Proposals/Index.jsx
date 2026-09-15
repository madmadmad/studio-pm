import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { ProposalStatusBadge } from '../../Components/StatusBadges';
import RichTextEditor from '../../Components/RichTextEditor';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

function emptyForm() {
    return { company_id: '', title: '', body: '', estimate_amount: '' };
}

export default function ProposalsIndex({ proposals, companies }) {
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(emptyForm());
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    async function saveProposal() {
        if (!form.company_id || !form.title || !form.body) {
            setError('Client, title, and scope of work are all required.');
            return;
        }
        setSaving(true);
        setError('');
        try {
            await api.post(`/api/companies/${form.company_id}/proposals`, {
                title: form.title,
                body: form.body,
                estimate_amount: form.estimate_amount || null,
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
                        <input
                            type="number"
                            min="0"
                            placeholder="Estimate amount ($)"
                            value={form.estimate_amount}
                            onChange={(e) => setForm({ ...form, estimate_amount: e.target.value })}
                            className="border border-border rounded px-3 py-2 text-sm font-mono"
                        />
                    </div>
                    <input
                        placeholder="Proposal title"
                        value={form.title}
                        onChange={(e) => setForm({ ...form, title: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm w-full mb-3"
                    />
                    <div className="mb-3">
                        <RichTextEditor value={form.body} onChange={(body) => setForm({ ...form, body })} />
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

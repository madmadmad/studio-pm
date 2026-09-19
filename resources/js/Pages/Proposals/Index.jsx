import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Check, Copy, Eye, PaperPlaneTilt, PencilSimple } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { ProposalStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

export default function ProposalsIndex({ proposals }) {
    const [copiedId, setCopiedId] = useState(null);

    async function sendProposal(proposal) {
        await api.post(`/api/proposals/${proposal.id}/send`);
        router.reload({ only: ['proposals'] });
    }

    function acceptLink(proposal) {
        return `${window.location.origin}/p/${proposal.accept_token}`;
    }

    function copyLink(proposal) {
        navigator.clipboard.writeText(acceptLink(proposal));
        setCopiedId(proposal.id);
        setTimeout(() => setCopiedId((id) => (id === proposal.id ? null : id)), 1500);
    }

    return (
        <AppLayout>
            <Head title="Proposals" />
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">Proposals</h1>
                <Link href="/proposals/create" className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">
                    New proposal
                </Link>
            </div>
            <p className="text-sm text-sage mb-6">{proposals.length} proposal{proposals.length !== 1 ? 's' : ''} on file.</p>

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {proposals.length === 0 ? (
                    <EmptyState text="No proposals yet." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Client</th>
                                <th className="px-4 py-2 font-medium">Project</th>
                                <th className="px-4 py-2 font-medium">Title</th>
                                <th className="px-4 py-2 font-medium">Estimate</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                                <th className="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {proposals.map((proposal) => (
                                <tr key={proposal.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{proposal.company.name}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {proposal.project ? (
                                            <Link href={`/projects/${proposal.project.id}`} className="hover:underline">
                                                {proposal.project.name}
                                            </Link>
                                        ) : '—'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link href={`/proposals/${proposal.id}/edit`} className="hover:underline">
                                            {proposal.title}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 tabular-nums">
                                        {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                                    </td>
                                    <td className="px-4 py-3"><ProposalStatusBadge proposal={proposal} /></td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-3">
                                            <a href={`/p/${proposal.accept_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="text-sage hover:text-ink">
                                                <Eye size={16} />
                                            </a>
                                            <Link href={`/proposals/${proposal.id}/edit`} title="Edit" className="text-pine hover:text-pine/70">
                                                <PencilSimple size={16} />
                                            </Link>
                                            {proposal.status === 'draft' && (
                                                <button onClick={() => sendProposal(proposal)} title="Send" className="text-brass hover:text-brass/70">
                                                    <PaperPlaneTilt size={16} />
                                                </button>
                                            )}
                                            {proposal.status !== 'draft' && (
                                                <button
                                                    onClick={() => copyLink(proposal)}
                                                    title={copiedId === proposal.id ? 'Copied!' : 'Copy link'}
                                                    className="text-sage hover:text-ink"
                                                >
                                                    {copiedId === proposal.id ? <Check size={16} /> : <Copy size={16} />}
                                                </button>
                                            )}
                                            {proposal.status === 'accepted' && (
                                                <span className="text-xs text-sage">{formatDate(proposal.accepted_at)}</span>
                                            )}
                                        </div>
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

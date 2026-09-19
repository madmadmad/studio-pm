import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { ProposalStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

export default function ProposalsIndex({ proposals }) {
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
                                        {proposal.contact && (
                                            <div className="text-xs text-sage">{proposal.contact.name}</div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link href={`/proposals/${proposal.id}/edit`} className="hover:underline">
                                            {proposal.title}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 font-mono">
                                        {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                                    </td>
                                    <td className="px-4 py-3"><ProposalStatusBadge proposal={proposal} /></td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-3">
                                            <Link href={`/proposals/${proposal.id}/edit`} className="text-sm font-medium text-pine hover:underline">
                                                Edit
                                            </Link>
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

import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Check, Copy, Eye, PaperPlaneTilt, PencilSimple, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { ProposalStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';
import { copyToClipboard } from '../../lib/clipboard';

export default function ProposalsIndex({ proposals: proposalsProp }) {
    const [proposals, setProposals] = useState(proposalsProp);
    const [copiedId, setCopiedId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);

    // Keeps local state in sync whenever a router.reload() elsewhere in this
    // component brings in a fresh copy of the prop.
    useEffect(() => {
        setProposals(proposalsProp);
    }, [proposalsProp]);

    async function sendProposal(proposal) {
        await api.post(`/api/proposals/${proposal.id}/send`);
        router.reload({ only: ['proposals'] });
    }

    function acceptLink(proposal) {
        return `${window.location.origin}/p/${proposal.accept_token}`;
    }

    async function copyLink(proposal) {
        const ok = await copyToClipboard(acceptLink(proposal));
        if (!ok) {
            alert('Could not copy the link. Copy it manually instead.');
            return;
        }
        setCopiedId(proposal.id);
        setTimeout(() => setCopiedId((id) => (id === proposal.id ? null : id)), 1500);
    }

    async function deleteProposal(proposal) {
        if (deletingId === proposal.id) return; // already in flight -- ignore a repeat click
        const amount = proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : 'this';
        const warning = `Delete ${amount} proposal "${proposal.title}" to ${proposal.company.name}? This can't be undone. The linked project (if any) is not affected.`;
        if (!confirm(warning)) return;
        setDeletingId(proposal.id);
        try {
            await api.delete(`/api/proposals/${proposal.id}`);
            setProposals((current) => current.filter((p) => p.id !== proposal.id));
        } catch (err) {
            alert(err.message || 'Could not delete this proposal.');
        } finally {
            setDeletingId(null);
        }
    }

    return (
        <AppLayout>
            <Head title="Proposals" />
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">Proposals</h1>
                <Link href="/proposals/create" className="btn btn-primary">
                    New proposal
                </Link>
            </div>
            <p className="text-sm text-shadow-grey mb-6">{proposals.length} proposal{proposals.length !== 1 ? 's' : ''} on file.</p>

            <div className="card overflow-hidden">
                {proposals.length === 0 ? (
                    <EmptyState text="No proposals yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Project</th>
                                <th>Title</th>
                                <th>Estimate</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {proposals.map((proposal) => (
                                <tr key={proposal.id}>
                                    <td>
                                        <div className="font-medium">{proposal.company.name}</div>
                                    </td>
                                    <td>
                                        {proposal.project ? (
                                            <Link href={`/projects/${proposal.project.id}`} className="hover:underline">
                                                {proposal.project.name}
                                            </Link>
                                        ) : '—'}
                                    </td>
                                    <td>
                                        <Link href={`/proposals/${proposal.id}/edit`} className="hover:underline">
                                            {proposal.title}
                                        </Link>
                                    </td>
                                    <td className="tabular-nums">
                                        {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                                    </td>
                                    <td><ProposalStatusBadge proposal={proposal} /></td>
                                    <td className="text-right">
                                        <div className="flex items-center justify-end gap-3">
                                            <a href={`/p/${proposal.accept_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="text-shadow-grey hover:text-gunmetal">
                                                <Eye size={16} />
                                            </a>
                                            <Link href={`/proposals/${proposal.id}/edit`} title="Edit" className="text-fern hover:text-fern/70">
                                                <PencilSimple size={16} />
                                            </Link>
                                            {proposal.status === 'draft' && (
                                                <button onClick={() => sendProposal(proposal)} title="Send" className="text-watermelon hover:text-watermelon/70">
                                                    <PaperPlaneTilt size={16} />
                                                </button>
                                            )}
                                            {proposal.status !== 'draft' && (
                                                <button
                                                    onClick={() => copyLink(proposal)}
                                                    title={copiedId === proposal.id ? 'Copied!' : 'Copy link'}
                                                    className="text-shadow-grey hover:text-gunmetal"
                                                >
                                                    {copiedId === proposal.id ? <Check size={16} /> : <Copy size={16} />}
                                                </button>
                                            )}
                                            {proposal.status === 'accepted' && (
                                                <span className="text-xs text-shadow-grey">{formatDate(proposal.accepted_at)}</span>
                                            )}
                                            {proposal.status !== 'accepted' && (
                                                <button
                                                    onClick={() => deleteProposal(proposal)}
                                                    disabled={deletingId === proposal.id}
                                                    title="Delete"
                                                    className="text-shadow-grey hover:text-fuchsia disabled:opacity-50"
                                                >
                                                    <Trash size={16} />
                                                </button>
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

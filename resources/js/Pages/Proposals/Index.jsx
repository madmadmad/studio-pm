import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CaretRight, Check, Copy, Eye, PaperPlaneTilt, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { ProposalStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';
import { copyToClipboard } from '../../lib/clipboard';
import PageHeader from '../../Components/PageHeader';
import Button from '../../Components/Button';
import ProposalDrawer from '../../Components/ProposalDrawer';
import { visitRow } from '../../lib/rowLink';

function reload() {
    router.reload({ only: ['proposals'] });
}

export default function ProposalsIndex({ proposals: proposalsProp, companies, services }) {
    const [proposals, setProposals] = useState(proposalsProp);
    // Proposals open -- and new ones start -- in the wide drawer, as on a
    // project or client.
    const [openId, setOpenId] = useState(null);
    const [creating, setCreating] = useState(false);
    const openProposal = proposals.find((p) => p.id === openId) || null;
    const [copiedId, setCopiedId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);

    // Keeps local state in sync whenever a router.reload() elsewhere in this
    // component brings in a fresh copy of the prop.
    useEffect(() => {
        setProposals(proposalsProp);
    }, [proposalsProp]);

    async function sendProposal(proposal) {
        await api.post(`/api/proposals/${proposal.id}/send`);
        reload();
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
            <PageHeader
                title="Proposals"
                actions={<Button onClick={() => setCreating(true)}>New proposal</Button>}
                subtitle={
                    <>
                        {proposals.length} proposal{proposals.length !== 1 ? 's' : ''} on file.
                    </>
                }
            />

            <div className="card card--flush">
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
                                <tr key={proposal.id} onClick={(e) => visitRow(e, null, { onOpen: () => setOpenId(proposal.id) })} className="table__row--link">
                                    <td className="table__cell--strong">
                                        <div>{proposal.company.name}</div>
                                    </td>
                                    <td>
                                        {proposal.project ? (
                                            <Link href={`/projects/${proposal.project.id}`} className="link">
                                                {proposal.project.name}
                                            </Link>
                                        ) : '—'}
                                    </td>
                                    <td>{proposal.title}</td>
                                    <td className="table__cell--numeric">
                                        {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                                    </td>
                                    <td><ProposalStatusBadge proposal={proposal} /></td>
                                    <td className="table__cell--end">
                                        <div className="table__actions">
                                            <a href={`/p/${proposal.accept_token}`} target="_blank" rel="noopener noreferrer" title="Preview" className="icon-btn icon-btn--secondary">
                                                <Eye />
                                            </a>
                                            {proposal.status === 'draft' && (
                                                <button onClick={() => sendProposal(proposal)} title="Send" className="icon-btn icon-btn--accent">
                                                    <PaperPlaneTilt />
                                                </button>
                                            )}
                                            {proposal.status !== 'draft' && (
                                                <button
                                                    onClick={() => copyLink(proposal)}
                                                    title={copiedId === proposal.id ? 'Copied!' : 'Copy link'}
                                                    className="icon-btn icon-btn--secondary"
                                                >
                                                    {copiedId === proposal.id ? <Check /> : <Copy />}
                                                </button>
                                            )}
                                            {proposal.status === 'accepted' && (
                                                <span className="table__note">{formatDate(proposal.accepted_at)}</span>
                                            )}
                                            {proposal.status !== 'accepted' && (
                                                <button
                                                    onClick={() => deleteProposal(proposal)}
                                                    disabled={deletingId === proposal.id}
                                                    title="Delete"
                                                    className="icon-btn icon-btn--danger"
                                                >
                                                    <Trash />
                                                </button>
                                            )}
                                            {/* The keyboard way in; the row's own click does the same. */}
                                            <button onClick={() => setOpenId(proposal.id)} title="Open proposal" aria-label="Open proposal" className="row-action">
                                                <CaretRight size={14} weight="bold" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {openProposal && (
                <ProposalDrawer
                    proposal={openProposal}
                    companies={companies}
                    services={services}
                    presetCompanyId={openProposal.company_id}
                    onChange={reload}
                    onClose={() => setOpenId(null)}
                />
            )}
            {creating && (
                <ProposalDrawer
                    proposal={null}
                    companies={companies}
                    services={services}
                    onChange={reload}
                    onClose={() => setCreating(false)}
                />
            )}
        </AppLayout>
    );
}

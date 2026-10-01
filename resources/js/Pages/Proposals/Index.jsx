import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CaretRight, Check, Copy, Eye, PaperPlaneTilt, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { ProposalStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, monthInAppTimezone, todayInAppTimezone } from '../../lib/format';
import MetricCard from '../../Components/MetricCard';
import { useListMotion } from '../../lib/listMotion';
import { api } from '../../lib/api';
import { copyToClipboard } from '../../lib/clipboard';
import PageHeader from '../../Components/PageHeader';
import ProposalDrawer from '../../Components/ProposalDrawer';
import SendProposalModal from '../../Components/SendProposalModal';
import { visitRow } from '../../lib/rowLink';
import TabToolbar from '../../Components/TabToolbar';

function reload() {
    router.reload({ only: ['proposals'] });
}

const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'sent', label: 'Sent' },
    { value: 'accepted', label: 'Accepted' },
];

// The figures across the top, as on the Invoices page: drafts and what's
// awaiting the client, then this month's sends and acceptances -- each a
// count and the estimates' total. Months are the firm's (Eastern).
function proposalMetrics(proposals) {
    const thisMonth = todayInAppTimezone().slice(0, 7);
    const inThisMonth = (value) => value && monthInAppTimezone(value) === thisMonth;
    const figure = (list) => ({ count: list.length, amount: list.reduce((s, p) => s + (parseFloat(p.estimate_amount) || 0), 0) });

    return {
        drafts: figure(proposals.filter((p) => p.status === 'draft')),
        awaiting: figure(proposals.filter((p) => p.status === 'sent')),
        sent: figure(proposals.filter((p) => inThisMonth(p.sent_at))),
        accepted: figure(proposals.filter((p) => p.status === 'accepted' && inThisMonth(p.accepted_at))),
    };
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
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('all');
    const rowsRef = useListMotion();

    // Search matches the title, client or project; the pills filter by
    // status. Same bar as the Projects, Clients and Invoices lists.
    const query = search.trim().toLowerCase();
    const visibleProposals = proposals.filter((p) => {
        if (filter !== 'all' && p.status !== filter) return false;
        if (!query) return true;
        return p.title?.toLowerCase().includes(query)
            || p.company?.name.toLowerCase().includes(query)
            || p.project?.name.toLowerCase().includes(query);
    });
    const metrics = proposalMetrics(proposals);

    // Keeps local state in sync whenever a router.reload() elsewhere in this
    // component brings in a fresh copy of the prop.
    useEffect(() => {
        setProposals(proposalsProp);
    }, [proposalsProp]);

    // A draft row's Send icon opens the Send Proposal dialog.
    const [sendingProposal, setSendingProposal] = useState(null);
    function sendProposal(proposal) {
        setSendingProposal(proposal);
    }
    function closeSendDialog() {
        setSendingProposal(null);
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
            />

            <div className="metric-grid">
                <MetricCard label={`Drafts (${metrics.drafts.count})`} value={formatCurrency(metrics.drafts.amount)} />
                <MetricCard label={`Awaiting response (${metrics.awaiting.count})`} value={formatCurrency(metrics.awaiting.amount)} />
                <MetricCard label={`Sent this month (${metrics.sent.count})`} value={formatCurrency(metrics.sent.amount)} />
                {/* The headline figure: the month's wins. */}
                <MetricCard label={`Accepted this month (${metrics.accepted.count})`} value={formatCurrency(metrics.accepted.amount)} tone="primary" />
            </div>

            {proposals.length > 0 && (
                <div className="filter-bar">
                    <input
                        type="search"
                        placeholder="Search proposals, clients or projects…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-label="Search proposals"
                        className="input filter-bar__search"
                    />
                    <div className="filter-bar__pills">
                        {STATUS_FILTERS.map((f) => (
                            <button
                                key={f.value}
                                onClick={() => setFilter(f.value)}
                                className={`filter-bar__pill${filter === f.value ? ' filter-bar__pill--active' : ''}`}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* The add action, directly above the list it adds to. */}
            <TabToolbar addLabel="New proposal" onAdd={() => setCreating(true)} />
            <div className="card card--flush">
                {proposals.length === 0 ? (
                    <EmptyState text="No proposals yet." />
                ) : visibleProposals.length === 0 ? (
                    <EmptyState text={query ? `No proposals match "${search.trim()}".` : 'No proposals match this filter.'} />
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
                        <tbody ref={rowsRef}>
                            {visibleProposals.map((proposal) => (
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
            {sendingProposal && <SendProposalModal proposal={sendingProposal} onClose={closeSendDialog} onSent={closeSendDialog} />}
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

import { Head } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '../../../Layouts/PortalLayout';
import PageHeader from '../../../Components/PageHeader';
import EmptyState from '../../../Components/EmptyState';
import MetricCard from '../../../Components/MetricCard';
import FilterBar from '../../../Components/FilterBar';
import ClientProposalsTable from '../../../Components/client/ClientProposalsTable';
import { formatCurrency, monthInAppTimezone, todayInAppTimezone } from '../../../lib/format';

const FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'sent', label: 'Awaiting review' },
    { value: 'accepted', label: 'Accepted' },
];

// Sent and accepted proposals. Rows open the public proposal page, where
// the client reviews and accepts. Across the top: what's waiting on their
// review (red when anything is) and what they've accepted this year.
export default function PortalProposalsIndex({ proposals }) {
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('all');

    const query = search.trim().toLowerCase();
    const visibleProposals = proposals.filter((p) => (filter === 'all' || p.status === filter)
        && (!query || p.title.toLowerCase().includes(query) || p.project?.name?.toLowerCase().includes(query)));

    const total = (list) => list.reduce((s, p) => s + (parseFloat(p.estimate_amount) || 0), 0);
    const awaiting = proposals.filter((p) => p.status === 'sent');
    const thisYear = todayInAppTimezone().slice(0, 4);
    const acceptedThisYear = proposals.filter((p) => p.status === 'accepted' && p.accepted_at && monthInAppTimezone(p.accepted_at).startsWith(thisYear));

    return (
        <PortalLayout>
            <Head title="Proposals" />
            <PageHeader title="Proposals" />

            <div className="metric-grid">
                <MetricCard
                    label={`Awaiting your review (${awaiting.length})`}
                    value={formatCurrency(total(awaiting))}
                    tone={awaiting.length > 0 ? 'primary' : null}
                />
                <MetricCard label={`Accepted in ${thisYear} (${acceptedThisYear.length})`} value={formatCurrency(total(acceptedThisYear))} />
            </div>

            {proposals.length > 0 && (
                <FilterBar
                    search={search}
                    onSearch={setSearch}
                    placeholder="Search proposals or projects…"
                    label="Search proposals"
                    filters={FILTERS}
                    value={filter}
                    onChange={setFilter}
                />
            )}

            {proposals.length === 0 || visibleProposals.length === 0 ? (
                <div className="card card--flush">
                    <EmptyState text={proposals.length === 0 ? 'No proposals yet.' : query ? `No proposals match "${search.trim()}".` : 'No proposals match this filter.'} />
                </div>
            ) : (
                <ClientProposalsTable proposals={visibleProposals} hrefFor={(proposal) => `/p/${proposal.accept_token}`} hideSent newTab />
            )}
        </PortalLayout>
    );
}

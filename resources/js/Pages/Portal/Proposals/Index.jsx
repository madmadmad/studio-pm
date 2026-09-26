import { Head } from '@inertiajs/react';
import PortalLayout from '../../../Layouts/PortalLayout';
import PageHeader from '../../../Components/PageHeader';
import EmptyState from '../../../Components/EmptyState';
import ClientProposalsTable from '../../../Components/client/ClientProposalsTable';

// Sent and accepted proposals. Rows open the public proposal page, where
// the client reviews and accepts.
export default function PortalProposalsIndex({ proposals }) {
    return (
        <PortalLayout>
            <Head title="Proposals" />
            <PageHeader title="Proposals" />

            {proposals.length === 0 ? (
                <div className="card card--flush">
                    <EmptyState text="No proposals yet." />
                </div>
            ) : (
                <ClientProposalsTable proposals={proposals} hrefFor={(proposal) => `/p/${proposal.accept_token}`} hideSent newTab />
            )}
        </PortalLayout>
    );
}

import { Head, router } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import PageHeader from '../../Components/PageHeader';
import ProposalEditor from '../../Components/ProposalEditor';
import { ProposalStatusBadge } from '../../Components/StatusBadges';

// The standalone proposal page (from the global Proposals list). A
// project's proposals open the same editor in a drawer instead.
export default function ProposalsForm({ proposal, companies, services, presetCompanyId, presetProjectId }) {
    const isEditing = !!proposal;

    return (
        <AppLayout>
            <Head title={isEditing ? `Edit — ${proposal.title}` : 'New proposal'} />
            <div className="page-column">
                <PageHeader
                    back={{ href: '/proposals', label: 'Proposals' }}
                    title={isEditing ? 'Edit proposal' : 'New proposal'}
                    actions={isEditing && <ProposalStatusBadge proposal={proposal} />}
                />

                <div className="card card--padded">
                    <ProposalEditor
                        proposal={proposal}
                        companies={companies}
                        services={services}
                        presetCompanyId={presetCompanyId}
                        presetProjectId={presetProjectId}
                        onSaved={() => router.visit('/proposals')}
                        onCancel={() => router.visit('/proposals')}
                        onChange={() => router.reload()}
                    />
                </div>
            </div>
        </AppLayout>
    );
}

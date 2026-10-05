import Drawer, { DrawerByline, DrawerDate } from './Drawer';
import { ProposalStatusBadge } from './StatusBadges';
import ProposalTeam from './ProposalTeam';
import ProposalAbout from './ProposalAbout';
import ProposalFeeSummary from './ProposalFeeSummary';
import { formatCurrency } from '../lib/format';

// An approved proposal, read-only, for someone on the project without the
// Proposals permission: the scope, disclaimer and fees as the client saw
// them, laid out like the public proposal page.
export default function ProposalView({ proposal, onClose }) {
    return (
        <Drawer size="wide" onClose={onClose}>
            <DrawerByline>
                <DrawerDate label="Accepted" date={proposal.accepted_at ?? proposal.created_at} />
                <ProposalStatusBadge proposal={proposal} />
            </DrawerByline>
            <h2 className="drawer__title">{proposal.title}</h2>
            <div className="document">
                {proposal.items.length === 0 && proposal.estimate_amount && (
                    <div className="document__estimate">Estimate: {formatCurrency(proposal.estimate_amount)}</div>
                )}
                {/* The editor's own HTML, written by staff. */}
                <div className="prose document__body" dangerouslySetInnerHTML={{ __html: proposal.body }} />
                {proposal.disclaimer && <p className="document__disclaimer">{proposal.disclaimer}</p>}
                {proposal.items.length > 0 && <ProposalFeeSummary proposal={proposal} />}
                <ProposalTeam team={proposal.team} />
                <ProposalAbout about={proposal.about} />
            </div>
        </Drawer>
    );
}

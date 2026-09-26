import { ProposalStatusBadge } from '../StatusBadges';
import { formatCurrency } from '../../lib/format';
import { visitRow } from '../../lib/rowLink';
import RowLink from './RowLink';

// A client's proposals: Title | Project | Estimate | Status, whole rows
// clickable. `hrefFor(proposal)` is where a row goes (the staff editor, or
// the public proposal page in the Client Hub). `newTab` opens rows in a
// new tab (the Client Hub, so it stays open). `hideSent` drops the Sent
// badge (the Client Hub only lists sent and accepted proposals, so Sent
// says nothing there); Accepted still shows.
export default function ClientProposalsTable({ proposals, hrefFor, hideSent = false, newTab = false }) {
    return (
        <div className="card card--flush">
            <table className="table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Project</th>
                        <th>Estimate</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    {proposals.map((proposal) => (
                        <tr key={proposal.id} onClick={(e) => visitRow(e, hrefFor(proposal), { newTab })} className="table__row--link">
                            <td className="table__cell--strong">
                                <RowLink newTab={newTab} href={hrefFor(proposal)} className="link">{proposal.title}</RowLink>
                            </td>
                            <td className="table__cell--muted">{proposal.project?.name ?? '—'}</td>
                            <td className="table__cell--numeric">
                                {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                            </td>
                            <td>{!(hideSent && proposal.status === 'sent') && <ProposalStatusBadge proposal={proposal} />}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

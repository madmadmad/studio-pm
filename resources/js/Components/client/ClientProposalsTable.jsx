import { Link } from '@inertiajs/react';
import { ProposalStatusBadge } from '../StatusBadges';
import { formatCurrency } from '../../lib/format';
import { visitRow } from '../../lib/rowLink';

// A client's proposals: Title | Project | Estimate | Status, whole rows
// clickable. `hrefFor(proposal)` is where a row goes (the staff editor, or
// the public proposal page in the Client Hub).
export default function ClientProposalsTable({ proposals, hrefFor }) {
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
                        <tr key={proposal.id} onClick={(e) => visitRow(e, hrefFor(proposal))} className="table__row--link">
                            <td className="table__cell--strong">
                                <Link href={hrefFor(proposal)} className="link">{proposal.title}</Link>
                            </td>
                            <td className="table__cell--muted">{proposal.project?.name ?? '—'}</td>
                            <td className="table__cell--numeric">
                                {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                            </td>
                            <td><ProposalStatusBadge proposal={proposal} /></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

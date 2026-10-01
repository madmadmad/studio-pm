import { CaretRight } from '@phosphor-icons/react';
import { ProposalStatusBadge } from '../StatusBadges';
import { formatCurrency } from '../../lib/format';
import { visitRow } from '../../lib/rowLink';
import RowLink from './RowLink';
import { useListMotion } from '../../lib/listMotion';

// A client's proposals: Title | Project | Estimate | Status, whole rows
// clickable. `hrefFor(proposal)` is where a row goes (the staff editor, or
// the public proposal page in the Client Hub). `newTab` opens rows in a
// new tab (the Client Hub, so it stays open). `hideSent` drops the Sent
// badge (the Client Hub only lists sent and accepted proposals, so Sent
// says nothing there); Accepted still shows. `onOpen(proposal)` opens a
// row in place instead -- the staff drawer -- and `hrefFor` isn't needed;
// each row then ends in the open caret, as every drawer list does.
export default function ClientProposalsTable({ proposals, hrefFor, hideSent = false, newTab = false, onOpen }) {
    const rowsRef = useListMotion();
    return (
        <div className="card card--flush">
            <table className="table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Project</th>
                        <th>Estimate</th>
                        <th>Status</th>
                        {onOpen && <th />}
                    </tr>
                </thead>
                <tbody ref={rowsRef}>
                    {proposals.map((proposal) => (
                        <tr key={proposal.id} onClick={(e) => visitRow(e, hrefFor?.(proposal), { newTab, onOpen: onOpen && (() => onOpen(proposal)) })} className="table__row--link">
                            <td className="table__cell--strong">
                                <RowLink newTab={newTab} href={hrefFor?.(proposal)} onOpen={onOpen && (() => onOpen(proposal))} className="link">{proposal.title}</RowLink>
                            </td>
                            <td className="table__cell--muted">{proposal.project?.name ?? '—'}</td>
                            <td className="table__cell--numeric">
                                {proposal.estimate_amount ? formatCurrency(proposal.estimate_amount) : '—'}
                            </td>
                            <td>{!(hideSent && proposal.status === 'sent') && <ProposalStatusBadge proposal={proposal} />}</td>
                            {onOpen && (
                                <td className="table__cell--end">
                                    <div className="table__actions">
                                        <button type="button" onClick={() => onOpen(proposal)} title="Open proposal" aria-label="Open proposal" className="row-action">
                                            <CaretRight size={14} weight="bold" />
                                        </button>
                                    </div>
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

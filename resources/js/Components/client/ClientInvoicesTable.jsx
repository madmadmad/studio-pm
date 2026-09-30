import { CaretRight } from '@phosphor-icons/react';
import { InvoiceStatusBadge } from '../StatusBadges';
import { displayInvoiceStatus, formatCurrency, formatDate, invoiceTotal } from '../../lib/format';
import { visitRow } from '../../lib/rowLink';
import RowLink from './RowLink';


// A client's invoices: # | Project | Issued | Due | Total | Status, whole
// rows clickable. `hrefFor(invoice)` is where a row goes (the staff
// invoice page, or the public invoice page in the Client Hub). An invoice
// carries either its items (staff) or a precomputed `total` (Client Hub).
// `newTab` opens rows in a new tab (the Client Hub, so it stays open).
// `onOpen(invoice)` opens a row in place instead -- the staff drawer --
// and `hrefFor` isn't needed; each row then ends in the open caret, as
// every drawer list does.
// `hideSent` drops the Sent badge (the Client Hub only ever lists sent
// invoices, so it says nothing there); Paid and Overdue still show.
export default function ClientInvoicesTable({ invoices, hrefFor, hideSent = false, newTab = false, onOpen }) {
    return (
        <div className="card card--flush">
            <table className="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Project</th>
                        <th>Issued</th>
                        <th>Due</th>
                        <th>Total</th>
                        <th>Status</th>
                        {onOpen && <th />}
                    </tr>
                </thead>
                <tbody>
                    {invoices.map((invoice) => (
                        <tr key={invoice.id} onClick={(e) => visitRow(e, hrefFor?.(invoice), { newTab, onOpen: onOpen && (() => onOpen(invoice)) })} className="table__row--link">
                            <td className="table__cell--numeric table__cell--strong">
                                <RowLink newTab={newTab} href={hrefFor?.(invoice)} onOpen={onOpen && (() => onOpen(invoice))} className="link">{invoice.invoice_number}</RowLink>
                            </td>
                            <td className="table__cell--muted">{invoice.project?.name ?? '—'}</td>
                            <td className="table__cell--muted">{formatDate(invoice.issued_on)}</td>
                            <td className="table__cell--muted">{formatDate(invoice.due_on)}</td>
                            <td className="table__cell--numeric">
                                {formatCurrency(invoice.total ?? invoiceTotal(invoice.items, invoice.surcharge))}
                            </td>
                            <td>{!(hideSent && displayInvoiceStatus(invoice) === 'sent') && <InvoiceStatusBadge invoice={invoice} />}</td>
                            {onOpen && (
                                <td className="table__cell--end">
                                    <div className="table__actions">
                                        <button type="button" onClick={() => onOpen(invoice)} title="Open invoice" aria-label="Open invoice" className="row-action">
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

import { InvoiceStatusBadge } from '../StatusBadges';
import { displayInvoiceStatus, formatCurrency, formatDate, invoiceTotal } from '../../lib/format';
import { visitRow } from '../../lib/rowLink';
import RowLink from './RowLink';


// A client's invoices: # | Project | Issued | Due | Total | Status, whole
// rows clickable. `hrefFor(invoice)` is where a row goes (the staff
// invoice page, or the public invoice page in the Client Hub). An invoice
// carries either its items (staff) or a precomputed `total` (Client Hub).
// `newTab` opens rows in a new tab (the Client Hub, so it stays open).
// `hideSent` drops the Sent badge (the Client Hub only ever lists sent
// invoices, so it says nothing there); Paid and Overdue still show.
export default function ClientInvoicesTable({ invoices, hrefFor, hideSent = false, newTab = false }) {
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
                    </tr>
                </thead>
                <tbody>
                    {invoices.map((invoice) => (
                        <tr key={invoice.id} onClick={(e) => visitRow(e, hrefFor(invoice), { newTab })} className="table__row--link">
                            <td className="table__cell--numeric table__cell--strong">
                                <RowLink newTab={newTab} href={hrefFor(invoice)} className="link">{invoice.invoice_number}</RowLink>
                            </td>
                            <td className="table__cell--muted">{invoice.project?.name ?? '—'}</td>
                            <td className="table__cell--muted">{formatDate(invoice.issued_on)}</td>
                            <td className="table__cell--muted">{formatDate(invoice.due_on)}</td>
                            <td className="table__cell--numeric">
                                {formatCurrency(invoice.total ?? invoiceTotal(invoice.items, invoice.surcharge))}
                            </td>
                            <td>{!(hideSent && displayInvoiceStatus(invoice) === 'sent') && <InvoiceStatusBadge invoice={invoice} />}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

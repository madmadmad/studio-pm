import { Link } from '@inertiajs/react';
import { InvoiceStatusBadge } from '../StatusBadges';
import { formatCurrency, formatDate, invoiceTotal } from '../../lib/format';
import { visitRow } from '../../lib/rowLink';

// A client's invoices: # | Project | Issued | Due | Total | Status, whole
// rows clickable. `hrefFor(invoice)` is where a row goes (the staff
// invoice page, or the public invoice page in the Client Hub). An invoice
// carries either its items (staff) or a precomputed `total` (Client Hub).
export default function ClientInvoicesTable({ invoices, hrefFor }) {
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
                        <tr key={invoice.id} onClick={(e) => visitRow(e, hrefFor(invoice))} className="table__row--link">
                            <td className="table__cell--numeric table__cell--strong">
                                <Link href={hrefFor(invoice)} className="link">{invoice.invoice_number}</Link>
                            </td>
                            <td className="table__cell--muted">{invoice.project?.name ?? '—'}</td>
                            <td className="table__cell--muted">{formatDate(invoice.issued_on)}</td>
                            <td className="table__cell--muted">{formatDate(invoice.due_on)}</td>
                            <td className="table__cell--numeric">
                                {formatCurrency(invoice.total ?? invoiceTotal(invoice.items, invoice.surcharge))}
                            </td>
                            <td><InvoiceStatusBadge invoice={invoice} /></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

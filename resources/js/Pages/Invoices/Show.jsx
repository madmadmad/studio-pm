import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceSurchargeAmount, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';

export default function InvoicesShow({ invoice }) {
    const subtotal = invoiceSubtotal(invoice.items);
    const surchargeAmount = invoiceSurchargeAmount(invoice.items, invoice.surcharge);
    const total = invoiceTotal(invoice.items, invoice.surcharge);

    async function sendInvoice() {
        await api.post(`/api/invoices/${invoice.id}/send`);
        router.reload();
    }

    async function markPaid() {
        await api.post(`/api/invoices/${invoice.id}/mark-paid`);
        router.reload();
    }

    return (
        <AppLayout>
            <Head title={`Invoice — ${invoice.company.name}`} />
            <div className="mb-1">
                <Link href="/invoices" className="text-sm text-sage hover:underline">&larr; Invoices</Link>
            </div>
            <div className="flex items-center justify-between mb-1">
                <h1 className="text-2xl font-semibold">{invoice.company.name}</h1>
                <InvoiceStatusBadge invoice={invoice} />
            </div>
            <p className="text-sm text-sage mb-6">
                Issued {formatDate(invoice.issued_on)} &middot; Due {formatDate(invoice.due_on)}
            </p>

            <div className="bg-white rounded-lg border border-border p-4 mb-6">
                <table className="w-full text-sm mb-4">
                    <thead>
                        <tr className="text-left border-b border-border text-sage">
                            <th className="py-2 font-medium">Description</th>
                            <th className="py-2 font-medium text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoice.items.map((item) => (
                            <tr key={item.id} className="border-b border-border last:border-b-0">
                                <td className="py-2">{item.description}</td>
                                <td className="py-2 text-right font-mono">{formatCurrency(item.amount)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <div className="text-sm space-y-1 ml-auto max-w-xs">
                    <div className="flex justify-between text-sage">
                        <span>Subtotal</span>
                        <span className="font-mono">{formatCurrency(subtotal)}</span>
                    </div>
                    {invoice.surcharge && (
                        <div className="flex justify-between text-sage">
                            <span>Card fee (3%)</span>
                            <span className="font-mono">{formatCurrency(surchargeAmount)}</span>
                        </div>
                    )}
                    <div className="flex justify-between font-semibold">
                        <span>Total</span>
                        <span className="font-mono">{formatCurrency(total)}</span>
                    </div>
                </div>
            </div>

            {invoice.payments.length > 0 && (
                <div className="bg-white rounded-lg border border-border p-4 mb-6">
                    <h2 className="text-sm font-semibold text-sage mb-3">Payments</h2>
                    <ul className="text-sm divide-y divide-border">
                        {invoice.payments.map((payment) => (
                            <li key={payment.id} className="py-2 flex justify-between">
                                <span>{formatDate(payment.paid_at)}</span>
                                <span className="font-mono">{formatCurrency(parseFloat(payment.amount) + parseFloat(payment.surcharge_amount))}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="flex gap-2">
                {invoice.status === 'draft' && (
                    <button onClick={sendInvoice} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">Send invoice</button>
                )}
                {invoice.status === 'sent' && (
                    <button onClick={markPaid} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded">Mark paid</button>
                )}
            </div>
        </AppLayout>
    );
}

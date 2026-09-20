import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceSurchargeAmount, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';

function InvoiceItems({ invoice }) {
    const subtotal = invoiceSubtotal(invoice.items);
    const surchargeAmount = invoiceSurchargeAmount(invoice.items, invoice.surcharge);
    const total = invoiceTotal(invoice.items, invoice.surcharge);

    return (
        <div className="mb-6">
            <div className="py-3 grid grid-cols-12 text-xs font-medium text-sage border-b border-border">
                <div className="col-span-8">Description</div>
                <div className="col-span-4 text-right">Amount</div>
            </div>

            {invoice.items.map((item) => (
                <div key={item.id} className="py-4 grid grid-cols-12 text-sm">
                    <div className="col-span-8">
                        <div className="font-medium">{item.description}</div>
                        {item.details && item.details !== item.description && (
                            <div className="text-xs text-sage mt-2 whitespace-pre-wrap">{item.details}</div>
                        )}
                    </div>
                    <div className="col-span-4 text-right tabular-nums">{formatCurrency(item.amount)}</div>
                </div>
            ))}

            <div className="pt-4 border-t border-border space-y-1">
                <div className="flex items-center justify-between text-sm text-sage">
                    <div>Subtotal</div>
                    <div className="tabular-nums">{formatCurrency(subtotal)}</div>
                </div>
                {invoice.surcharge && (
                    <div className="flex items-center justify-between text-sm text-sage">
                        <div>Card processing fee (3%)</div>
                        <div className="tabular-nums">{formatCurrency(surchargeAmount)}</div>
                    </div>
                )}
                <div className="flex items-center justify-between pt-2">
                    <div className="font-display text-lg font-semibold">Total</div>
                    <div className="tabular-nums text-lg font-semibold">{formatCurrency(total)}</div>
                </div>
            </div>
        </div>
    );
}

export default function InvoiceShow({ invoice, studio }) {
    const paidAt = invoice.payments[0]?.paid_at;
    const [paying, setPaying] = useState(false);
    const [error, setError] = useState('');

    async function payNow() {
        setPaying(true);
        setError('');
        try {
            const { url } = await api.post(`/api/invoices/${invoice.public_token}/checkout`);
            window.location.href = url;
        } catch (err) {
            setError(err.message);
            setPaying(false);
        }
    }

    return (
        <div className="min-h-screen bg-white text-ink px-4 py-10">
            <Head title={`Invoice — ${invoice.company.name}`} />
            <div className="max-w-[800px] mx-auto p-[60px] rounded-[6px] shadow-[0_20px_60px_rgba(35,38,46,0.12)]">
                <img src="/images/studio-lockup.svg" alt="Studio" className="w-[180px] h-auto mb-8" />

                <div className="grid grid-cols-2 gap-4 pb-8 mb-8 border-b border-border text-sm">
                    <div className="text-sage">
                        <div className="font-medium text-ink">{studio.name}</div>
                        {studio.address && <div className="whitespace-pre-line">{studio.address}</div>}
                        {studio.email && <div>{studio.email}</div>}
                        {studio.phone && <div>{studio.phone}</div>}
                        {studio.website && <div>{studio.website}</div>}
                    </div>
                    <div className="border-l border-border pl-5">
                        <div className="text-xs font-semibold text-sage mb-2">Client</div>
                        <div className="font-medium text-ink">{invoice.company.name}</div>
                        {invoice.project && <div className="text-sage">{invoice.project.name}</div>}
                        {invoice.project?.po_number && <div className="text-sage">PO #{invoice.project.po_number}</div>}
                    </div>
                </div>

                <h1 className="font-display text-2xl font-semibold pb-2">
                    <span className="font-sans font-normal text-sage">Invoice </span>
                    #{invoice.invoice_number}
                </h1>
                <div className="text-sm text-sage pb-8 mb-8 border-b border-border">
                    Issued {formatDate(invoice.issued_on)} &middot; Due {formatDate(invoice.due_on)}
                    {invoice.contact && <> &middot; Billed to {invoice.contact.name}</>}
                </div>

                <InvoiceItems invoice={invoice} />

                {invoice.status === 'paid' ? (
                    <div className="rounded-lg p-4 bg-pine-soft text-pine text-sm font-medium">
                        Paid{paidAt ? ` on ${formatDate(paidAt)}` : ''}. Thank you!
                    </div>
                ) : invoice.status === 'sent' ? (
                    <div>
                        <div className="mb-1 text-sm text-ink">Pay online by credit card</div>
                        <button
                            onClick={payNow}
                            disabled={paying}
                            className="bg-brass text-white text-sm font-medium px-4 py-2 rounded hover:bg-brass/90 transition-colors disabled:opacity-50"
                        >
                            {paying ? 'Redirecting…' : 'Pay Now'}
                        </button>
                        {error && <div className="text-sm text-brick mt-2">{error}</div>}

                        {studio.payment_instructions && (
                            <div className="mt-6 pt-6 border-t border-border">
                                <div className="mb-1 text-sm text-ink">Prefer to pay by ACH or check?</div>
                                <div className="text-sm text-sage whitespace-pre-wrap">{studio.payment_instructions}</div>
                            </div>
                        )}

                        <div className="text-xs text-sage mt-4">Due {formatDate(invoice.due_on)}</div>
                    </div>
                ) : (
                    <div className="text-sm text-sage">Due {formatDate(invoice.due_on)}.</div>
                )}
            </div>
        </div>
    );
}

import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceTotal } from '../../lib/format';
import { api } from '../../lib/api';

// No card-fee row here on purpose -- that fee only exists between the
// client and Stripe if they choose to pay by card, broken out on Stripe's
// own checkout page. This invoice's total is always just the sum of items.
function InvoiceItems({ invoice }) {
    const subtotal = invoiceSubtotal(invoice.items);
    const total = invoiceTotal(invoice.items, invoice.surcharge);

    return (
        <div className="mb-6">
            <div className="py-3 grid grid-cols-12 text-xs font-medium text-shadow-grey border-b border-border">
                <div className="col-span-8">Description</div>
                <div className="col-span-4 text-right">Amount</div>
            </div>

            {invoice.items.map((item) => (
                <div key={item.id} className="py-4 grid grid-cols-12 text-sm">
                    <div className="col-span-8">
                        <div className="font-medium">{item.description}</div>
                        {item.details && item.details !== item.description && (
                            <div className="text-xs text-shadow-grey mt-2 whitespace-pre-wrap">{item.details}</div>
                        )}
                    </div>
                    <div className="col-span-4 text-right tabular-nums">{formatCurrency(item.amount)}</div>
                </div>
            ))}

            <div className="pt-4 border-t border-border space-y-1">
                <div className="flex items-center justify-between text-sm text-shadow-grey">
                    <div>Subtotal</div>
                    <div className="tabular-nums">{formatCurrency(subtotal)}</div>
                </div>
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
    const [paying, setPaying] = useState(null); // null | 'card' | 'ach'
    const [error, setError] = useState('');

    async function pay(method) {
        setPaying(method);
        setError('');
        try {
            const { url } = await api.post(`/api/invoices/${invoice.public_token}/checkout`, { method });
            window.location.href = url;
        } catch (err) {
            setError(err.message);
            setPaying(null);
        }
    }

    return (
        <div className="min-h-screen bg-white text-gunmetal px-4 py-10">
            <Head title={`Invoice — ${invoice.company.name}`} />
            <div className="max-w-[800px] mx-auto p-[60px] rounded-[6px] shadow-[0_20px_60px_rgba(35,38,46,0.12)]">
                <img src="/images/studio-lockup.svg" alt="Studio" className="w-[180px] h-auto mb-8" />

                <div className="grid grid-cols-2 gap-4 pb-8 mb-8 border-b border-border text-sm">
                    <div className="text-shadow-grey">
                        <div className="font-medium text-gunmetal">{studio.name}</div>
                        {studio.address && <div className="whitespace-pre-line">{studio.address}</div>}
                        {studio.email && <div>{studio.email}</div>}
                        {studio.phone && <div>{studio.phone}</div>}
                        {studio.website && <div>{studio.website}</div>}
                    </div>
                    <div className="border-l border-border pl-5">
                        <div className="text-xs font-semibold text-shadow-grey mb-2">Client</div>
                        <div className="font-medium text-gunmetal">{invoice.company.name}</div>
                        {invoice.project && <div className="text-shadow-grey">{invoice.project.name}</div>}
                        {invoice.project?.po_number && <div className="text-shadow-grey">PO #{invoice.project.po_number}</div>}
                    </div>
                </div>

                <h1 className="font-display text-2xl font-semibold pb-2">
                    <span className="font-sans font-normal text-shadow-grey">Invoice </span>
                    #{invoice.invoice_number}
                </h1>
                <div className="text-sm text-shadow-grey pb-8 mb-8 border-b border-border">
                    Issued {formatDate(invoice.issued_on)} &middot; Due {formatDate(invoice.due_on)}
                    {invoice.contact && <> &middot; Billed to {invoice.contact.name}</>}
                </div>

                <InvoiceItems invoice={invoice} />

                {invoice.status === 'paid' ? (
                    <div className="rounded-lg p-4 bg-fern-soft text-fern text-sm font-medium">
                        Paid{paidAt ? ` on ${formatDate(paidAt)}` : ''}. Thank you!
                    </div>
                ) : invoice.status === 'sent' ? (
                    <div>
                        <div className="mb-2 text-sm text-gunmetal">Pay online</div>
                        <div className="flex flex-wrap gap-3">
                            {invoice.surcharge && (
                                <button
                                    onClick={() => pay('card')}
                                    disabled={paying !== null}
                                    className="bg-watermelon text-white text-sm font-medium px-4 py-2 rounded hover:bg-watermelon/90 transition-colors disabled:opacity-50"
                                >
                                    {paying === 'card' ? 'Redirecting…' : 'Pay by card — 3% fee applies'}
                                </button>
                            )}
                            <button
                                onClick={() => pay('ach')}
                                disabled={paying !== null}
                                className="bg-gunmetal text-white text-sm font-medium px-4 py-2 rounded hover:bg-gunmetal/90 transition-colors disabled:opacity-50"
                            >
                                {paying === 'ach' ? 'Redirecting…' : 'Pay by ACH — no fee'}
                            </button>
                        </div>
                        {error && <div className="text-sm text-watermelon mt-2">{error}</div>}

                        {studio.payment_instructions && (
                            <div className="mt-6 pt-6 border-t border-border">
                                <div className="mb-1 text-sm font-semibold text-gunmetal">Prefer to pay by check?</div>
                                <div className="text-sm text-shadow-grey whitespace-pre-wrap">{studio.payment_instructions}</div>
                            </div>
                        )}

                        <div className="text-xs text-shadow-grey mt-4">Due {formatDate(invoice.due_on)}</div>
                    </div>
                ) : (
                    <div className="text-sm text-shadow-grey">Due {formatDate(invoice.due_on)}.</div>
                )}
            </div>
        </div>
    );
}

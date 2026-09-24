import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceTotal } from '../../lib/format';
import { paymentTermsLabel } from '../../lib/paymentTerms';
import { api } from '../../lib/api';

// No card-fee row here on purpose -- that fee only exists between the
// client and Stripe if they choose to pay by card, broken out on Stripe's
// own checkout page. This invoice's total is always just the sum of items.
function InvoiceItems({ invoice }) {
    const subtotal = invoiceSubtotal(invoice.items);
    const total = invoiceTotal(invoice.items, invoice.surcharge);

    return (
        <div className="document__items">
            <div className="document__items-head">
                <div>Description</div>
                <div className="document__item-amount">Amount</div>
            </div>

            {invoice.items.map((item) => (
                <div key={item.id} className="document__item">
                    <div>
                        <div className="document__item-name">{item.description}</div>
                        {item.details && item.details !== item.description && (
                            <div className="document__item-details">{item.details}</div>
                        )}
                    </div>
                    <div className="document__item-amount document__amount">{formatCurrency(item.amount)}</div>
                </div>
            ))}

            <div className="document__totals">
                <div className="document__total-row document__total-row--muted">
                    <div>Subtotal</div>
                    <div className="document__amount">{formatCurrency(subtotal)}</div>
                </div>
                <div className="document__total-row document__total-row--grand">
                    <div className="document__total-label">Total</div>
                    <div className="document__total-value">{formatCurrency(total)}</div>
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
        <div className="document-page">
            <Head title={`Invoice — ${invoice.company.name}`} />
            <div className="document">
                <img src="/images/studio-lockup.svg" alt="Studio" className="document__logo" />

                <div className="document__parties">
                    <div className="document__from">
                        <div className="document__party-name">{studio.name}</div>
                        {studio.address && <div className="document__address">{studio.address}</div>}
                        {studio.email && <div>{studio.email}</div>}
                        {studio.phone && <div>{studio.phone}</div>}
                        {studio.website && <div>{studio.website}</div>}
                    </div>
                    <div className="document__to">
                        <div className="section-label">Client</div>
                        <div className="document__party-name">{invoice.company.name}</div>
                        {invoice.project && <div className="document__muted">{invoice.project.name}</div>}
                        {invoice.project?.po_number && <div className="document__muted">PO #{invoice.project.po_number}</div>}
                    </div>
                </div>

                <h1 className="document__title">
                    <span className="document__title-prefix">Invoice </span>
                    #{invoice.invoice_number}
                </h1>
                <div className="document__meta">
                    Issued {formatDate(invoice.issued_on)} &middot; Due {formatDate(invoice.due_on)}
                    {paymentTermsLabel(invoice.payment_terms) !== 'Custom' && ` (${paymentTermsLabel(invoice.payment_terms)})`}
                    {invoice.contact && <> &middot; Billed to {invoice.contact.name}</>}
                </div>

                <InvoiceItems invoice={invoice} />

                {invoice.status === 'paid' ? (
                    <div className="document__notice">
                        Paid{paidAt ? ` on ${formatDate(paidAt)}` : ''}. Thank you!
                    </div>
                ) : invoice.status === 'sent' ? (
                    <div>
                        <div className="document__pay-label">Pay online</div>
                        <div className="document__pay-actions">
                            {invoice.surcharge && (
                                <button
                                    onClick={() => pay('card')}
                                    disabled={paying !== null}
                                    className="btn btn--lg btn--accent"
                                >
                                    {paying === 'card' ? 'Redirecting…' : 'Pay by card — 3% fee applies'}
                                </button>
                            )}
                            <button
                                onClick={() => pay('ach')}
                                disabled={paying !== null}
                                className="btn btn--lg btn--primary"
                            >
                                {paying === 'ach' ? 'Redirecting…' : 'Pay by ACH — no fee'}
                            </button>
                        </div>
                        {error && <div className="form-message form-message--error document__pay-error">{error}</div>}

                        {studio.payment_instructions && (
                            <div className="document__instructions">
                                <div className="document__instructions-title">Prefer to pay by check?</div>
                                <div className="document__instructions-text">{studio.payment_instructions}</div>
                            </div>
                        )}

                        <div className="document__due">Due {formatDate(invoice.due_on)}</div>
                    </div>
                ) : (
                    <div className="document__note">Due {formatDate(invoice.due_on)}.</div>
                )}
            </div>
        </div>
    );
}

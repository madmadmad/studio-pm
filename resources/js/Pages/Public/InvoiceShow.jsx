import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { DownloadSimple } from '@phosphor-icons/react';
import DocumentFrom from '../../Components/DocumentFrom';
import { formatCurrency, formatDate, invoiceSubtotal, invoiceTax, invoiceTotal, taxLabel } from '../../lib/format';
import { paymentTermsLabel } from '../../lib/paymentTerms';
import { api } from '../../lib/api';
import DetailField from '../../Components/DocumentDetailField';

// No card-fee row here on purpose -- that fee only exists between the
// client and Stripe if they choose to pay by card, broken out on Stripe's
// own checkout page. The total is the items plus any sales tax.
function InvoiceItems({ invoice }) {
    const subtotal = invoiceSubtotal(invoice.items);
    const total = invoiceTotal(invoice.items, invoice.surcharge, invoice.tax_rate);
    const taxed = invoice.tax_rate != null;

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
                {taxed && (
                    <div className="document__total-row document__total-row--muted">
                        <div>{taxLabel(invoice.tax_name, invoice.tax_rate)} on {formatCurrency(invoiceSubtotal(invoice.items.filter((item) => item.taxable)))}</div>
                        <div className="document__amount">{formatCurrency(invoiceTax(invoice.items, invoice.tax_rate))}</div>
                    </div>
                )}
                <div className="document__total-row document__total-row--grand">
                    <div className="document__total-label">Total</div>
                    <div className="document__total-value">{formatCurrency(total)}</div>
                </div>
            </div>
        </div>
    );
}

// One labeled value in the invoice's details column.
// Street, then "City, ST 12345" -- whichever parts the company has.
function companyAddressLines(company) {
    const cityStateZip = [company.city, [company.state, company.postal_code].filter(Boolean).join(' ')]
        .filter(Boolean)
        .join(', ');
    return [company.address_line1, cityStateZip].filter(Boolean);
}

export default function InvoiceShow({ invoice, studio }) {
    const { branding } = usePage().props;
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
                <a
                    href={`/i/${invoice.public_token}/pdf`}
                    title="Download PDF"
                    aria-label="Download PDF"
                    className="icon-btn icon-btn--secondary icon-btn--lg document__download"
                >
                    <DownloadSimple />
                </a>
                <img src={branding.logo} alt={branding.name} className="document__logo" />

                {/* Laid out like a proposal's: "Invoice" on its own line, then
                    what it's for (its project, else category or client); the
                    number's with the dates below. */}
                <h1 className="document__title document__title--spaced">
                    <span className="document__title-prefix">Invoice</span>
                    {invoice.document_title}
                </h1>

                {/* From | Bill to, then the invoice's own details two
                    across below. */}
                <div className="document__details">
                    <div className="document__details-row">
                        <DocumentFrom studio={studio} />
                        <div>
                            <div className="section-label section-label--ruled">Bill to</div>
                            {invoice.contact && <div className="document__party-name">{invoice.contact.name}</div>}
                            <div className={invoice.contact ? 'document__muted' : 'document__party-name'}>{invoice.company.name}</div>
                            {companyAddressLines(invoice.company).map((line) => (
                                <div key={line} className="document__muted">{line}</div>
                            ))}
                            {invoice.contact?.email && <div className="document__muted">{invoice.contact.email}</div>}
                        </div>
                    </div>
                    <div className="document__details-row document__details-row--fields document__details-row--three">
                        <DetailField label="Invoice number">{invoice.invoice_number}</DetailField>
                        <DetailField label="Issued on">{formatDate(invoice.issued_on)}</DetailField>
                        <DetailField label="Due date">
                            {formatDate(invoice.due_on)}
                            {paymentTermsLabel(invoice.payment_terms) !== 'Custom' && (
                                <span className="document__muted"> ({paymentTermsLabel(invoice.payment_terms)})</span>
                            )}
                        </DetailField>
                        {invoice.project?.po_number && <DetailField label="PO number">{invoice.project.po_number}</DetailField>}
                    </div>
                </div>

                <InvoiceItems invoice={invoice} />

                {invoice.status === 'paid' ? (
                    <div className="document__notice">
                        Paid{paidAt ? ` on ${formatDate(paidAt)}` : ''}. Thank you!
                    </div>
                ) : invoice.status === 'sent' ? (
                    <div>
                        {/* Pay online | ACH/check instructions, side by side.
                            ACH is handled manually for now (the instructions),
                            so card is the only online option; the ACH
                            checkout endpoint is still there. */}
                        <div className="document__pay">
                            {invoice.surcharge && (
                                <div>
                                    <div className="section-label">Pay online</div>
                                    <div className="document__pay-actions">
                                        <button
                                            onClick={() => pay('card')}
                                            disabled={paying !== null}
                                            className="btn btn--lg btn--accent"
                                        >
                                            {paying === 'card' ? 'Redirecting…' : 'Pay by card — 3% fee applies'}
                                        </button>
                                    </div>
                                    {error && <div className="form-message form-message--error document__pay-error">{error}</div>}
                                </div>
                            )}

                            {studio.payment_instructions && (
                                <div>
                                    <div className="section-label">
                                        {invoice.surcharge ? 'Prefer to pay by ACH or check?' : 'How to pay'}
                                    </div>
                                    <div className="document__instructions-text">{studio.payment_instructions}</div>
                                </div>
                            )}
                        </div>

                    </div>
                ) : (
                    <div className="document__note">Due {formatDate(invoice.due_on)}.</div>
                )}
            </div>
        </div>
    );
}

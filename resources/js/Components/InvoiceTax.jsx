import { usePage } from '@inertiajs/react';
import Toggle from './Toggle';
import { formatCurrency, invoiceSubtotal, invoiceTax, taxLabel } from '../lib/format';

// Sales tax on an invoice: switched on per invoice, charged on the lines
// marked taxable. A form keeps it as `tax_rate` / `tax_name` (null when
// off) and sends `tax: true|false`; the server copies the rate onto the
// invoice so a later rate change never re-rates it.

// The tax a form switches on: the invoice's own when it already has one,
// otherwise the studio's current rate (config/invoicing.php).
export function useSalesTax(invoice = null) {
    const shared = usePage().props.salesTax;
    return invoice?.tax_rate != null ? { name: invoice.tax_name, rate: invoice.tax_rate } : shared;
}

// The form's on/off switch. Turning it on with no line marked taxable
// marks them all, to untick the ones that aren't (most aren't taxed, so
// starting from none would show $0 tax and look broken).
export function TaxToggle({ form, onChange, salesTax }) {
    if (!salesTax) return null;

    function toggle(on) {
        if (!on) {
            onChange({ tax_rate: null, tax_name: null });
            return;
        }
        const items = form.items.some((item) => item.taxable) ? form.items : form.items.map((item) => ({ ...item, taxable: true }));
        onChange({ tax_rate: salesTax.rate, tax_name: salesTax.name, items });
    }

    return <Toggle checked={form.tax_rate != null} onChange={toggle} label="Charge Tax" />;
}

// The tax line in a totals block -- "Ohio sales tax (7.25%) on $500.00" --
// when the invoice charges tax.
export function TaxRow({ items, taxName, taxRate }) {
    if (taxRate == null) return null;
    const taxable = invoiceSubtotal(items.filter((item) => item.taxable));
    return (
        <div className="totals__row totals__row--muted">
            <span>{taxLabel(taxName, taxRate)} on {formatCurrency(taxable)}</span>
            <span className="totals__value">{formatCurrency(invoiceTax(items, taxRate))}</span>
        </div>
    );
}

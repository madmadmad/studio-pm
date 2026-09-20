export function formatCurrency(n) {
    return `$${Number(n || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

export function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export function invoiceSubtotal(items) {
    return items.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0);
}

// A hypothetical 3% card-processing fee -- shown only as a preview while
// composing an invoice (does the studio want to offer a card option at
// all?) and on Stripe's own checkout page if the client picks card. Never
// added into invoiceTotal(): the fee is between the client and Stripe and
// never affects what the invoice itself is worth in this app.
export function cardSurchargeAmount(items, surcharge) {
    return surcharge ? invoiceSubtotal(items) * 0.03 : 0;
}

// What the client owes. The `surcharge` param is accepted (rather than
// changing every call site's arity) but no longer affects the result --
// kept only because it used to change the total, and it's easy to forget a
// call site if this silently changes shape.
export function invoiceTotal(items, _surcharge) {
    return invoiceSubtotal(items);
}

export function isOverdue(invoice) {
    return invoice.status === 'sent' && invoice.due_on && new Date(invoice.due_on) < new Date();
}

export function displayInvoiceStatus(invoice) {
    return isOverdue(invoice) ? 'overdue' : invoice.status;
}

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

export function invoiceSurchargeAmount(items, surcharge) {
    return surcharge ? invoiceSubtotal(items) * 0.03 : 0;
}

export function invoiceTotal(items, surcharge) {
    return invoiceSubtotal(items) + invoiceSurchargeAmount(items, surcharge);
}

export function isOverdue(invoice) {
    return invoice.status === 'sent' && invoice.due_on && new Date(invoice.due_on) < new Date();
}

export function displayInvoiceStatus(invoice) {
    return isOverdue(invoice) ? 'overdue' : invoice.status;
}

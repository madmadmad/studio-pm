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

// The full "October 11, 2023, 3:30 PM" form -- used as a tooltip/title
// alongside formatRelativeTime's short form, so the exact moment is always
// one hover away.
export function formatDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

// "2h ago" for anything recent, falling back to an absolute date once it's
// far enough back that "N days ago" stops being a useful unit -- the full
// timestamp is always still available via formatDateTime in a title attr.
export function formatRelativeTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    const seconds = Math.round((Date.now() - date.getTime()) / 1000);

    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    if (seconds < 86400 * 6) return `${Math.floor(seconds / 86400)}d ago`;

    return formatDate(value);
}

// "Today" / "Yesterday" / an absolute date -- the date-separator rows
// between messages sent on different calendar days.
export function formatDaySeparator(value) {
    const date = new Date(value);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);

    const isSameDay = (a, b) => a.toDateString() === b.toDateString();

    if (isSameDay(date, today)) return 'Today';
    if (isSameDay(date, yesterday)) return 'Yesterday';

    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
}

export function isSameDay(a, b) {
    return new Date(a).toDateString() === new Date(b).toDateString();
}

export function formatFileSize(bytes) {
    if (!bytes) return '0 KB';
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unitIndex = 0;
    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }
    return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
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

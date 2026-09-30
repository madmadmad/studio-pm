import { api } from './api';

// An emailed invoice goes out on the queue (SendInvoiceEmailJob), and only
// becomes "sent" once the job has actually sent it -- after the Send
// request has already returned. These let a page follow up, so its list
// shows the new status without a browser refresh.

// Whether an email send for this invoice is still waiting on the queue.
export function hasQueuedEmail(invoice) {
    return (invoice?.invoice_sends || []).some((send) => send.type === 'email' && send.status === 'queued');
}

// Re-checks the invoice until its queued email has gone out (or failed, or
// been cancelled), then resolves with the fresh invoice. Resolves null if
// it's still queued after `timeout` -- the queue worker isn't running.
export async function waitForQueuedSend(invoiceId, { interval = 1500, timeout = 30000 } = {}) {
    const started = Date.now();

    while (Date.now() - started < timeout) {
        await new Promise((resolve) => setTimeout(resolve, interval));
        const { invoice } = await api.get(`/api/invoices/${invoiceId}`);
        if (!hasQueuedEmail(invoice)) return invoice;
    }

    return null;
}

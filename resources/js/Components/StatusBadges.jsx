import { Eye } from '@phosphor-icons/react';
import Badge from './Badge';
import { displayInvoiceStatus } from '../lib/format';
import { formatDateTimeEastern } from '../lib/datetime';

// When the client last opened a sent invoice's or proposal's link
// (App\Support\DocumentViews): "Oct 11, 2:14 PM ET · 3 views".
function whenOpened(doc) {
    const views = doc.view_count > 1 ? ` · ${doc.view_count} views` : '';
    return `${formatDateTimeEastern(doc.last_viewed_at || doc.first_viewed_at)}${views}`;
}

// The Opened badge's tooltip.
export function openedSummary(doc) {
    return doc?.first_viewed_at ? `Opened ${whenOpened(doc)}` : null;
}

// For a drawer's byline, beside the Opened badge: the eye and when --
// nothing until it's been opened.
export function OpenedNote({ doc }) {
    if (!doc?.first_viewed_at) return null;
    const summary = whenOpened(doc);
    return (
        <span className="drawer__date">
            <Eye className="drawer__date-icon" aria-hidden="true" />
            {summary}
        </span>
    );
}

const INVOICE_STATUS = {
    draft: { tone: 'neutral', label: 'Draft' },
    scheduled: { tone: 'neutral', label: 'Scheduled' },
    sent: { tone: 'accent', label: 'Sent' },
    opened: { tone: 'info', label: 'Opened' },
    partial: { tone: 'warning', label: 'Partially paid' },
    paid: { tone: 'success', label: 'Paid' },
    refunded: { tone: 'neutral', label: 'Refunded' },
    overdue: { tone: 'danger', label: 'Overdue' },
};

// Everything paid on it has gone back to the client.
function refundedInFull(invoice) {
    const sum = (rows) => (rows || []).reduce((total, row) => total + parseFloat(row.amount), 0);
    return invoice.refunds?.length > 0 && sum(invoice.refunds) >= sum(invoice.payments) - 0.005;
}

export function InvoiceStatusBadge({ invoice }) {
    // Sent and not yet overdue: part paid, or whether the client has
    // opened it.
    let status = displayInvoiceStatus(invoice);
    if (status === 'paid' && refundedInFull(invoice)) status = 'refunded';
    else if (status === 'sent' && invoice.payments?.length) status = 'partial';
    else if (status === 'sent' && invoice.first_viewed_at) status = 'opened';
    const m = INVOICE_STATUS[status] || INVOICE_STATUS.draft;
    return <Badge tone={m.tone} label={m.label} title={openedSummary(invoice) ?? undefined} />;
}

const PROPOSAL_STATUS = {
    draft: { tone: 'neutral', label: 'Draft' },
    sent: { tone: 'accent', label: 'Sent' },
    opened: { tone: 'info', label: 'Opened' },
    accepted: { tone: 'success', label: 'Accepted' },
};

export function ProposalStatusBadge({ proposal }) {
    const status = proposal.status === 'sent' && proposal.first_viewed_at ? 'opened' : proposal.status;
    const m = PROPOSAL_STATUS[status] || PROPOSAL_STATUS.draft;
    return <Badge tone={m.tone} label={m.label} title={openedSummary(proposal) ?? undefined} />;
}

export function CompanyStatusBadge({ company }) {
    return company.status === 'active'
        ? <Badge tone="success" label="Active" />
        : <Badge tone="neutral" label="Inactive" />;
}

const TASK_STATUS = {
    todo: { tone: 'neutral', label: 'To do' },
    in_progress: { tone: 'accent', label: 'In progress' },
    done: { tone: 'success', label: 'Done' },
};

export function TaskStatusBadge({ task }) {
    const m = TASK_STATUS[task.status] || TASK_STATUS.todo;
    return <Badge tone={m.tone} label={m.label} />;
}

const PROJECT_STATUS = {
    leads: { tone: 'neutral', label: 'Leads' },
    estimated: { tone: 'accent', label: 'Estimated' },
    active: { tone: 'success', label: 'Active' },
    inactive: { tone: 'neutral', label: 'Inactive' },
    completed: { tone: 'neutral', label: 'Completed' },
    archived: { tone: 'neutral', label: 'Archived' },
};

export function ProjectStatusBadge({ project }) {
    const m = PROJECT_STATUS[project.status] || PROJECT_STATUS.active;
    return <Badge tone={m.tone} label={m.label} />;
}

const EXPENSE_STATUS = {
    unbilled: { tone: 'neutral', label: 'Unbilled' },
    billed: { tone: 'accent', label: 'Billed' },
    billed_and_paid: { tone: 'success', label: 'Paid' },
};

export function ExpenseStatusBadge({ expense }) {
    const m = EXPENSE_STATUS[expense.billing_status] || EXPENSE_STATUS.unbilled;
    return <Badge tone={m.tone} label={m.label} />;
}

// A time entry is billable or not (from its service). Time isn't invoiced
// hour by hour, so there's no billed/unbilled state to show.
export function TimeEntryStatusBadge({ entry }) {
    return entry.billable
        ? <Badge tone="success" label="Billable" />
        : <Badge tone="neutral" label="Non-billable" />;
}

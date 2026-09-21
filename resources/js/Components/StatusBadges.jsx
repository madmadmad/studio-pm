import Badge from './Badge';
import { displayInvoiceStatus } from '../lib/format';

const INVOICE_STATUS = {
    draft: { tone: 'neutral', label: 'Draft' },
    sent: { tone: 'watermelon', label: 'Sent' },
    paid: { tone: 'fern', label: 'Paid' },
    overdue: { tone: 'fuchsia', label: 'Overdue' },
};

export function InvoiceStatusBadge({ invoice }) {
    const status = displayInvoiceStatus(invoice);
    const m = INVOICE_STATUS[status] || INVOICE_STATUS.draft;
    return <Badge tone={m.tone} label={m.label} />;
}

const PROPOSAL_STATUS = {
    draft: { tone: 'neutral', label: 'Draft' },
    sent: { tone: 'watermelon', label: 'Sent' },
    accepted: { tone: 'fern', label: 'Accepted' },
};

export function ProposalStatusBadge({ proposal }) {
    const m = PROPOSAL_STATUS[proposal.status] || PROPOSAL_STATUS.draft;
    return <Badge tone={m.tone} label={m.label} />;
}

export function CompanyStatusBadge({ company }) {
    return company.status === 'active'
        ? <Badge tone="fern" label="Active" />
        : <Badge tone="neutral" label="Inactive" />;
}

const TASK_STATUS = {
    todo: { tone: 'neutral', label: 'To do' },
    in_progress: { tone: 'watermelon', label: 'In progress' },
    done: { tone: 'fern', label: 'Done' },
};

export function TaskStatusBadge({ task }) {
    const m = TASK_STATUS[task.status] || TASK_STATUS.todo;
    return <Badge tone={m.tone} label={m.label} />;
}

const PROJECT_STATUS = {
    leads: { tone: 'neutral', label: 'Leads' },
    estimated: { tone: 'watermelon', label: 'Estimated' },
    active: { tone: 'fern', label: 'Active' },
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
    billed: { tone: 'watermelon', label: 'Billed' },
    billed_and_paid: { tone: 'fern', label: 'Paid' },
};

export function ExpenseStatusBadge({ expense }) {
    const m = EXPENSE_STATUS[expense.billing_status] || EXPENSE_STATUS.unbilled;
    return <Badge tone={m.tone} label={m.label} />;
}

import Badge from './Badge';
import { displayInvoiceStatus } from '../lib/format';

const INVOICE_STATUS = {
    draft: { tone: 'neutral', label: 'Draft' },
    sent: { tone: 'brass', label: 'Sent' },
    paid: { tone: 'pine', label: 'Paid' },
    overdue: { tone: 'brick', label: 'Overdue' },
};

export function InvoiceStatusBadge({ invoice }) {
    const status = displayInvoiceStatus(invoice);
    const m = INVOICE_STATUS[status] || INVOICE_STATUS.draft;
    return <Badge tone={m.tone} label={m.label} />;
}

const PROPOSAL_STATUS = {
    draft: { tone: 'neutral', label: 'Draft' },
    sent: { tone: 'brass', label: 'Sent' },
    accepted: { tone: 'pine', label: 'Accepted' },
};

export function ProposalStatusBadge({ proposal }) {
    const m = PROPOSAL_STATUS[proposal.status] || PROPOSAL_STATUS.draft;
    return <Badge tone={m.tone} label={m.label} />;
}

export function CompanyStatusBadge({ company }) {
    return company.status === 'active'
        ? <Badge tone="pine" label="Active" />
        : <Badge tone="neutral" label="Inactive" />;
}

const TASK_STATUS = {
    todo: { tone: 'neutral', label: 'To do' },
    in_progress: { tone: 'brass', label: 'In progress' },
    done: { tone: 'pine', label: 'Done' },
};

export function TaskStatusBadge({ task }) {
    const m = TASK_STATUS[task.status] || TASK_STATUS.todo;
    return <Badge tone={m.tone} label={m.label} />;
}

const PROJECT_STATUS = {
    estimated: { tone: 'brass', label: 'Estimated' },
    active: { tone: 'pine', label: 'Active' },
    inactive: { tone: 'neutral', label: 'Inactive' },
    completed: { tone: 'neutral', label: 'Completed' },
    archived: { tone: 'neutral', label: 'Archived' },
};

export function ProjectStatusBadge({ project }) {
    const m = PROJECT_STATUS[project.status] || PROJECT_STATUS.active;
    return <Badge tone={m.tone} label={m.label} />;
}

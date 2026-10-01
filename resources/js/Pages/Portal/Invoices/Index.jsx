import { Head } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '../../../Layouts/PortalLayout';
import PageHeader from '../../../Components/PageHeader';
import EmptyState from '../../../Components/EmptyState';
import MetricCard from '../../../Components/MetricCard';
import FilterBar from '../../../Components/FilterBar';
import ClientInvoicesTable from '../../../Components/client/ClientInvoicesTable';
import { displayInvoiceStatus, formatCurrency, monthInAppTimezone, todayInAppTimezone } from '../../../lib/format';

const FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'outstanding', label: 'Outstanding' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'paid', label: 'Paid' },
];

// Sent and paid invoices (billing and primary contacts only), with what's
// owed at a glance, as on the staff Invoices page: outstanding, paid this
// year, and overdue last -- red only when something is. Rows open the
// public invoice page, where the client pays or downloads the PDF.
export default function PortalInvoicesIndex({ invoices }) {
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('all');

    const matchesFilter = (invoice) => {
        const status = displayInvoiceStatus(invoice);
        if (filter === 'outstanding') return status === 'sent' || status === 'overdue';
        return filter === 'all' || status === filter;
    };
    const query = search.trim().toLowerCase();
    const visibleInvoices = invoices.filter((i) => matchesFilter(i)
        && (!query || String(i.invoice_number).includes(query) || i.project?.name?.toLowerCase().includes(query)));

    // What's still owed: each unpaid invoice's balance after payments.
    const unpaid = invoices.filter((i) => i.status === 'sent');
    const overdue = unpaid.filter((i) => displayInvoiceStatus(i) === 'overdue');
    const thisYear = todayInAppTimezone().slice(0, 4);
    const paidThisYear = invoices.filter((i) => i.paid_at && monthInAppTimezone(i.paid_at).startsWith(thisYear));
    const sum = (list, field) => list.reduce((s, i) => s + (parseFloat(i[field]) || 0), 0);

    return (
        <PortalLayout>
            <Head title="Invoices" />
            <PageHeader title="Invoices" />

            <div className="metric-grid">
                <MetricCard label={`Outstanding (${unpaid.length})`} value={formatCurrency(sum(unpaid, 'balance'))} />
                <MetricCard label={`Paid in ${thisYear} (${paidThisYear.length})`} value={formatCurrency(sum(paidThisYear, 'total'))} />
                <MetricCard
                    label={`Overdue (${overdue.length})`}
                    value={formatCurrency(sum(overdue, 'balance'))}
                    tone={overdue.length > 0 ? 'primary' : null}
                />
            </div>

            {invoices.length > 0 && (
                <FilterBar
                    search={search}
                    onSearch={setSearch}
                    placeholder="Search invoice numbers or projects…"
                    label="Search invoices"
                    filters={FILTERS}
                    value={filter}
                    onChange={setFilter}
                />
            )}

            {invoices.length === 0 || visibleInvoices.length === 0 ? (
                <div className="card card--flush">
                    <EmptyState text={invoices.length === 0 ? 'No invoices yet.' : query ? `No invoices match "${search.trim()}".` : 'No invoices match this filter.'} />
                </div>
            ) : (
                <ClientInvoicesTable invoices={visibleInvoices} hrefFor={(invoice) => `/i/${invoice.public_token}`} clientView newTab />
            )}
        </PortalLayout>
    );
}

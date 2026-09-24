import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import EmptyState from '../../Components/EmptyState';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate } from '../../lib/format';
import PageHeader from '../../Components/PageHeader';

export default function DashboardIndex({ metrics, recentInvoices }) {
    return (
        <AppLayout>
            <Head title="Overview" />
            <PageHeader
                title="Overview"
                subtitle="Snapshot of billing and client activity."
            />

            <div className="grid grid-cols-3 gap-4 mb-8">
                <MetricCard label="Outstanding" value={formatCurrency(metrics.outstanding)} />
                <MetricCard label="Unbilled hours" value={`${metrics.unbilled_hours}h`} />
                <MetricCard label="Active clients" value={metrics.active_clients} />
            </div>

            <h2 className="text-sm font-semibold mb-3 text-shadow-grey">Recent invoices</h2>
            <div className="card">
                {recentInvoices.length === 0 ? (
                    <EmptyState text="No invoices yet." />
                ) : (
                    recentInvoices.map((invoice) => (
                        <Link
                            key={invoice.id}
                            href={`/invoices/${invoice.id}`}
                            className="flex items-center justify-between px-4 py-3 border-b border-border last:border-b-0 hover:bg-porcelain"
                        >
                            <div>
                                <div className="text-sm font-medium">{invoice.company_name}</div>
                                <div className="text-xs text-shadow-grey">{formatDate(invoice.issued_on)}</div>
                            </div>
                            <div className="flex items-center gap-3">
                                <span className="tabular-nums text-sm">{formatCurrency(invoice.total)}</span>
                                <InvoiceStatusBadge invoice={invoice} />
                            </div>
                        </Link>
                    ))
                )}
            </div>
        </AppLayout>
    );
}

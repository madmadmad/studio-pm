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

            <div className="metric-grid">
                <MetricCard label="Outstanding" value={formatCurrency(metrics.outstanding)} />
                <MetricCard label="Unbilled hours" value={`${metrics.unbilled_hours}h`} />
                <MetricCard label="Active clients" value={metrics.active_clients} />
            </div>

            <h2 className="section-heading">Recent invoices</h2>
            <div className="card">
                {recentInvoices.length === 0 ? (
                    <EmptyState text="No invoices yet." />
                ) : (
                    recentInvoices.map((invoice) => (
                        <Link
                            key={invoice.id}
                            href={`/invoices/${invoice.id}`}
                            className="list-row"
                        >
                            <div>
                                <div className="list-row__title">{invoice.company_name}</div>
                                <div className="list-row__meta">{formatDate(invoice.issued_on)}</div>
                            </div>
                            <div className="list-row__aside">
                                <span className="list-row__amount">{formatCurrency(invoice.total)}</span>
                                <InvoiceStatusBadge invoice={invoice} />
                            </div>
                        </Link>
                    ))
                )}
            </div>
        </AppLayout>
    );
}

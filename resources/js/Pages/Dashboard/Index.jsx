import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import EmptyState from '../../Components/EmptyState';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate } from '../../lib/format';

export default function DashboardIndex({ metrics, recentInvoices }) {
    return (
        <AppLayout>
            <Head title="Overview" />
            <h1 className="font-display text-2xl font-semibold mb-1">Overview</h1>
            <p className="text-sm text-sage mb-6">Snapshot of billing and client activity.</p>

            <div className="grid grid-cols-3 gap-4 mb-8">
                <MetricCard label="Outstanding" value={formatCurrency(metrics.outstanding)} />
                <MetricCard label="Unbilled hours" value={`${metrics.unbilled_hours}h`} />
                <MetricCard label="Active clients" value={metrics.active_clients} />
            </div>

            <h2 className="text-sm font-semibold mb-3 text-sage">Recent invoices</h2>
            <div className="bg-white rounded-lg border border-border">
                {recentInvoices.length === 0 ? (
                    <EmptyState text="No invoices yet." />
                ) : (
                    recentInvoices.map((invoice) => (
                        <Link
                            key={invoice.id}
                            href={`/invoices/${invoice.id}`}
                            className="flex items-center justify-between px-4 py-3 border-b border-border last:border-b-0 hover:bg-paper"
                        >
                            <div>
                                <div className="text-sm font-medium">{invoice.company_name}</div>
                                <div className="text-xs text-sage">{formatDate(invoice.issued_on)}</div>
                            </div>
                            <div className="flex items-center gap-3">
                                <span className="font-mono text-sm">{formatCurrency(invoice.total)}</span>
                                <InvoiceStatusBadge invoice={invoice} />
                            </div>
                        </Link>
                    ))
                )}
            </div>
        </AppLayout>
    );
}

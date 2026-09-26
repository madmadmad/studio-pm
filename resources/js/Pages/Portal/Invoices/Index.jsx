import { Head } from '@inertiajs/react';
import PortalLayout from '../../../Layouts/PortalLayout';
import PageHeader from '../../../Components/PageHeader';
import EmptyState from '../../../Components/EmptyState';
import MetricCard from '../../../Components/MetricCard';
import ClientInvoicesTable from '../../../Components/client/ClientInvoicesTable';
import { formatCurrency, isOverdue } from '../../../lib/format';

// Sent and paid invoices (billing and primary contacts only), with what's
// owed at a glance. Rows open the public invoice page, where the client
// pays or downloads the PDF.
export default function PortalInvoicesIndex({ invoices }) {
    // What's still owed: each unpaid invoice's balance after payments.
    const unpaid = invoices.filter((invoice) => invoice.status === 'sent');
    const outstanding = unpaid.reduce((sum, invoice) => sum + invoice.balance, 0);
    const overdue = unpaid.filter(isOverdue).reduce((sum, invoice) => sum + invoice.balance, 0);

    return (
        <PortalLayout>
            <Head title="Invoices" />
            <PageHeader title="Invoices" />

            <div className="metric-grid metric-grid--loose">
                <MetricCard label="Outstanding" value={formatCurrency(outstanding)} />
                <MetricCard label="Overdue" value={formatCurrency(overdue)} tone={overdue > 0 ? 'watermelon' : null} />
            </div>

            {invoices.length === 0 ? (
                <div className="card card--flush">
                    <EmptyState text="No invoices yet." />
                </div>
            ) : (
                <ClientInvoicesTable invoices={invoices} hrefFor={(invoice) => `/i/${invoice.public_token}`} hideSent newTab />
            )}
        </PortalLayout>
    );
}

import { Head } from '@inertiajs/react';
import PortalLayout from '../../../Layouts/PortalLayout';
import PageHeader from '../../../Components/PageHeader';
import EmptyState from '../../../Components/EmptyState';
import ClientInvoicesTable from '../../../Components/client/ClientInvoicesTable';

// Sent and paid invoices (billing and primary contacts only). Rows open the
// public invoice page, where the client pays or downloads the PDF.
export default function PortalInvoicesIndex({ invoices }) {
    return (
        <PortalLayout>
            <Head title="Invoices" />
            <PageHeader title="Invoices" />

            {invoices.length === 0 ? (
                <div className="card card--flush">
                    <EmptyState text="No invoices yet." />
                </div>
            ) : (
                <ClientInvoicesTable invoices={invoices} hrefFor={(invoice) => `/i/${invoice.public_token}`} />
            )}
        </PortalLayout>
    );
}

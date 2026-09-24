import { Head, router } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import PageHeader from '../../Components/PageHeader';
import InvoiceDetail, { InvoiceDueLine } from '../../Components/InvoiceDetail';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatDate } from '../../lib/format';

// The standalone invoice page (from the global Invoices list). A
// project's invoices open the same detail in a drawer instead.
export default function InvoicesShow({ invoice, studio, invoicingDefaults }) {
    return (
        <InvoiceDetail
            invoice={invoice}
            studio={studio}
            invoicingDefaults={invoicingDefaults}
            onChange={() => router.reload()}
            renderFrame={({ invoice: current, actions, children }) => (
                <AppLayout>
                    <Head title={`Invoice — ${current.company.name}`} />
                    <div className="page-column">
                        <PageHeader
                            back={{ href: '/invoices', label: 'Invoices' }}
                            title={current.company.name}
                            actions={
                                <>
                                    <InvoiceStatusBadge invoice={current} />
                                    {actions}
                                </>
                            }
                            subtitle={
                                <>
                                    Invoice #{current.invoice_number} &middot; Issued {formatDate(current.issued_on)} &middot; <InvoiceDueLine invoice={current} />
                                </>
                            }
                        />
                        {children}
                    </div>
                </AppLayout>
            )}
        />
    );
}

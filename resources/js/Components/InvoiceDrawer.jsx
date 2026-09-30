import { useState } from 'react';
import { Trash } from '@phosphor-icons/react';
import Drawer, { DrawerByline, DrawerDate } from './Drawer';
import InvoiceDetail, { InvoiceDueLine } from './InvoiceDetail';
import { InvoiceStatusBadge } from './StatusBadges';
import { formatCurrency, invoiceTotal } from '../lib/format';
import { api } from '../lib/api';

// An invoice in the wide drawer: the shared InvoiceDetail (as on the
// standalone page) in the standard drawer frame -- byline, title, and the
// invoice actions beside the close button, plus delete. `detail` is the
// /api/invoices/{id} payload; `onRefresh` reloads it after a change;
// `onChange` refreshes the page's own data (after a delete).
export default function InvoiceDrawer({ detail, onRefresh, onChange, onClose }) {
    async function deleteInvoice(invoice) {
        const amount = formatCurrency(invoiceTotal(invoice.items, invoice.surcharge));
        if (!confirm(`Delete this ${amount} invoice? This can't be undone.`)) return;
        try {
            await api.delete(`/api/invoices/${invoice.id}`);
            onClose();
            onChange();
        } catch (err) {
            alert(err.message || 'Could not delete this invoice.');
        }
    }

    return (
        <InvoiceDetail
            invoice={detail.invoice}
            studio={detail.studio}
            invoicingDefaults={detail.invoicingDefaults}
            onChange={onRefresh}
            bare
            renderFrame={({ invoice, actions, children }) => (
                <Drawer
                    size="wide"
                    onClose={onClose}
                    actions={
                        <>
                            {actions}
                            {invoice.status !== 'paid' && (
                                <button onClick={() => deleteInvoice(invoice)} title="Delete invoice" className="icon-btn icon-btn--danger drawer__action">
                                    <Trash />
                                </button>
                            )}
                        </>
                    }
                >
                    <DrawerByline>
                        <DrawerDate label="Issued" date={invoice.issued_on} />
                        <InvoiceStatusBadge invoice={invoice} />
                    </DrawerByline>
                    <h2 className="drawer__title">Invoice #{invoice.invoice_number}</h2>
                    <p className="drawer__meta drawer__section">
                        <InvoiceDueLine invoice={invoice} />
                    </p>
                    {children}
                </Drawer>
            )}
        />
    );
}

// Opening an invoice drawer from a list. The drawer opens once its invoice
// has loaded, rather than opening empty and filling in -- swapping the
// content in would replay the drawer's entrance. `onChange` refreshes the
// page's data. Render `drawer` wherever the list is.
export function useInvoiceDrawer(onChange) {
    const [detail, setDetail] = useState(null); // the open invoice's /api/invoices/{id} payload
    const [loadingId, setLoadingId] = useState(null);

    async function openInvoice(id) {
        if (loadingId) return;
        setLoadingId(id);
        try {
            setDetail(await api.get(`/api/invoices/${id}`));
        } finally {
            setLoadingId(null);
        }
    }

    async function refreshInvoice() {
        setDetail(await api.get(`/api/invoices/${detail.invoice.id}`));
        onChange();
    }

    const drawer = detail && (
        <InvoiceDrawer
            key={detail.invoice.id}
            detail={detail}
            onRefresh={refreshInvoice}
            onChange={onChange}
            onClose={() => setDetail(null)}
        />
    );

    return { openInvoice, loadingId, drawer };
}

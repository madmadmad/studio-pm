import { Head, Link } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { CaretDown, CaretRight, DownloadSimple } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import PageHeader from '../../Components/PageHeader';
import { InvoiceStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency, formatDate } from '../../lib/format';

// Invoices by category for a year: every invoice issued that year (sent or
// paid), grouped by what it was for -- project work, Hosting... -- with
// each group's count, total, paid and outstanding. A category opens to its
// invoices. Built by App\Services\InvoiceCategoryReport.
export default function InvoiceCategories({ report, years }) {
    const [open, setOpen] = useState(() => report.categories.find((c) => c.id !== null && c.count > 0)?.name ?? null);
    const { totals } = report;

    return (
        <AppLayout>
            <Head title="Invoices by category" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="Invoices by category"
                subtitle="Every invoice issued in the year, sent or paid, by what it was for."
                actions={(
                    <a href={`/bookkeeping/invoice-categories.csv?year=${report.year}`} className="btn btn--secondary">
                        <DownloadSimple /> Download CSV
                    </a>
                )}
            />

            <div className="filter-bar">
                <div className="filter-bar__pills">
                    {years.map((year) => (
                        <Link
                            key={year}
                            href={`/bookkeeping/invoice-categories?year=${year}`}
                            preserveScroll
                            className={`filter-bar__pill${year === report.year ? ' filter-bar__pill--active' : ''}`}
                        >
                            {year}
                        </Link>
                    ))}
                </div>
            </div>

            <div className="metric-grid">
                <MetricCard label={`Invoiced in ${report.year} (${totals.count})`} value={formatCurrency(totals.total)} tone="primary" />
                <MetricCard label="Paid" value={formatCurrency(totals.paid)} />
                <MetricCard label="Outstanding" value={formatCurrency(totals.outstanding)} />
            </div>

            <div className="card card--flush">
                <table className="table sales-tax">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th className="table__cell--end">Invoices</th>
                            <th className="table__cell--end">Total</th>
                            <th className="table__cell--end">Paid</th>
                            <th className="table__cell--end">Outstanding</th>
                            <th aria-label="Invoices" />
                        </tr>
                    </thead>
                    <tbody>
                        {report.categories.map((category) => {
                            const hasInvoices = category.invoices.length > 0;
                            const isOpen = open === category.name;
                            return (
                                <Fragment key={category.name}>
                                    <tr
                                        onClick={hasInvoices ? () => setOpen(isOpen ? null : category.name) : undefined}
                                        className={hasInvoices ? 'table__row--link' : undefined}
                                    >
                                        <td className="table__cell--strong">{category.name}</td>
                                        <td className="table__cell--end table__cell--numeric">{category.count}</td>
                                        <td className="table__cell--end table__cell--numeric table__cell--strong">{formatCurrency(category.total)}</td>
                                        <td className="table__cell--end table__cell--numeric">{formatCurrency(category.paid)}</td>
                                        <td className="table__cell--end table__cell--numeric table__cell--muted">{formatCurrency(category.outstanding)}</td>
                                        <td className="table__cell--end">
                                            {hasInvoices && (
                                                <button type="button" aria-expanded={isOpen} aria-label={`${isOpen ? 'Hide' : 'Show'} ${category.name} invoices`} className="row-action">
                                                    {isOpen ? <CaretDown size={14} weight="bold" /> : <CaretRight size={14} weight="bold" />}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                    {isOpen && category.invoices.map((invoice) => (
                                        <tr key={invoice.id} className="sales-tax__payment">
                                            <td>
                                                #{invoice.invoice_number} &middot; {formatDate(invoice.issued_on)}
                                                <span className="sales-tax__client"> &middot; {invoice.client}{invoice.project ? ` · ${invoice.project}` : ''}</span>
                                            </td>
                                            <td className="table__cell--end">
                                                <InvoiceStatusBadge invoice={invoice} />
                                            </td>
                                            <td className="table__cell--end table__cell--numeric">{formatCurrency(invoice.total)}</td>
                                            <td />
                                            <td className="table__cell--end table__cell--numeric">{invoice.balance > 0 ? formatCurrency(invoice.balance) : ''}</td>
                                            <td />
                                        </tr>
                                    ))}
                                </Fragment>
                            );
                        })}
                    </tbody>
                    <tfoot>
                        <tr className="sales-tax__total">
                            <td>Total {report.year}</td>
                            <td className="table__cell--end table__cell--numeric">{totals.count}</td>
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.total)}</td>
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.paid)}</td>
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.outstanding)}</td>
                            <td />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </AppLayout>
    );
}

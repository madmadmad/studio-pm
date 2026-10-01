import { Head, Link } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { CaretDown, CaretRight, DownloadSimple } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import PageHeader from '../../Components/PageHeader';
import { formatCurrency, formatDate } from '../../lib/format';

// The monthly sales tax report: per month, the figures a sales tax return
// asks for (gross, taxable and exempt sales, tax collected), by when the
// money came in. A month with tax opens to the payments behind it.
// Built by App\Services\SalesTaxReport.
export default function SalesTax({ report, years }) {
    const [openMonth, setOpenMonth] = useState(null);
    const { totals } = report;

    return (
        <AppLayout>
            <Head title="Sales tax" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="Sales tax"
                subtitle="Month by month, by the date each payment came in. Gross sales leave out the tax itself."
                actions={(
                    <a href={`/bookkeeping/sales-tax.csv?year=${report.year}`} className="btn btn--secondary">
                        <DownloadSimple /> Download CSV
                    </a>
                )}
            />

            {/* One pill per year with income, the current one red. */}
            <div className="filter-bar">
                <div className="filter-bar__pills">
                    {years.map((year) => (
                        <Link
                            key={year}
                            href={`/bookkeeping/sales-tax?year=${year}`}
                            preserveScroll
                            className={`filter-bar__pill${year === report.year ? ' filter-bar__pill--active' : ''}`}
                        >
                            {year}
                        </Link>
                    ))}
                </div>
            </div>

            <div className="metric-grid">
                <MetricCard label={`Gross sales (${report.year})`} value={formatCurrency(totals.gross_sales)} />
                <MetricCard label="Taxable sales" value={formatCurrency(totals.taxable_sales)} />
                <MetricCard label="Sales tax collected" value={formatCurrency(totals.tax)} />
            </div>

            <div className="card card--flush">
                <table className="table sales-tax">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th className="table__cell--end">Gross sales</th>
                            <th className="table__cell--end">Taxable sales</th>
                            <th className="table__cell--end">Exempt sales</th>
                            <th className="table__cell--end">Tax collected</th>
                            <th aria-label="Payments" />
                        </tr>
                    </thead>
                    <tbody>
                        {report.months.map((month) => {
                            const hasPayments = month.payments.length > 0;
                            const open = openMonth === month.month;
                            return (
                                <Fragment key={month.month}>
                                    <tr
                                        onClick={hasPayments ? () => setOpenMonth(open ? null : month.month) : undefined}
                                        className={hasPayments ? 'table__row--link' : undefined}
                                    >
                                        <td className="table__cell--strong">{month.label}</td>
                                        <td className="table__cell--end table__cell--numeric">{formatCurrency(month.gross_sales)}</td>
                                        <td className="table__cell--end table__cell--numeric">{formatCurrency(month.taxable_sales)}</td>
                                        <td className="table__cell--end table__cell--numeric table__cell--muted">{formatCurrency(month.exempt_sales)}</td>
                                        <td className="table__cell--end table__cell--numeric table__cell--strong">{formatCurrency(month.tax)}</td>
                                        <td className="table__cell--end">
                                            {hasPayments && (
                                                <button
                                                    type="button"
                                                    aria-expanded={open}
                                                    aria-label={`${open ? 'Hide' : 'Show'} ${month.label} payments`}
                                                    className="row-action"
                                                >
                                                    {open ? <CaretDown size={14} weight="bold" /> : <CaretRight size={14} weight="bold" />}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                    {/* The month's taxed payments, in its own columns. */}
                                    {open && month.payments.map((payment) => (
                                        <tr key={payment.id} className="sales-tax__payment">
                                            <td>
                                                {formatDate(payment.date)}
                                                {' · '}
                                                {payment.invoice_number ? `Invoice #${payment.invoice_number}` : (payment.description || 'Manual entry')}
                                                {payment.client && <span className="sales-tax__client"> · {payment.client}</span>}
                                            </td>
                                            <td />
                                            <td className="table__cell--end table__cell--numeric">{formatCurrency(payment.taxable_sales)}</td>
                                            <td />
                                            <td className="table__cell--end table__cell--numeric">{formatCurrency(payment.tax)}</td>
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
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.gross_sales)}</td>
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.taxable_sales)}</td>
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.exempt_sales)}</td>
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.tax)}</td>
                            <td />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </AppLayout>
    );
}

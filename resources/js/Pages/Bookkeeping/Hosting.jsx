import { Head, Link } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { CaretDown, CaretRight, DownloadSimple } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import PageHeader from '../../Components/PageHeader';
import { formatCurrency } from '../../lib/format';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const percent = (value) => (value === null || value === undefined ? '—' : `${value}%`);

// Hosting profitability for a year: per client, what they were invoiced
// for hosting (paid beside it) against their share of the hosting bills
// (expense splits), and the margin; a client opens to their months.
// Hosting bills not split yet show as Unassigned. Built by
// App\Services\HostingProfitabilityReport.
export default function Hosting({ report, years }) {
    const [open, setOpen] = useState(null);
    const { totals } = report;

    return (
        <AppLayout>
            <Head title="Hosting profitability" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="Hosting profitability"
                subtitle="Each client's hosting invoices for the year against their share of the hosting bills."
                actions={(
                    <a href={`/bookkeeping/hosting.csv?year=${report.year}`} className="btn btn--secondary">
                        <DownloadSimple /> Download CSV
                    </a>
                )}
            />

            <div className="filter-bar">
                <div className="filter-bar__pills">
                    {years.map((year) => (
                        <Link
                            key={year}
                            href={`/bookkeeping/hosting?year=${year}`}
                            preserveScroll
                            className={`filter-bar__pill${year === report.year ? ' filter-bar__pill--active' : ''}`}
                        >
                            {year}
                        </Link>
                    ))}
                </div>
            </div>

            <div className="metric-grid">
                <MetricCard label={`Hosting invoiced in ${report.year}`} value={formatCurrency(totals.invoiced)} />
                <MetricCard label="Hosting cost" value={formatCurrency(totals.cost)} />
                <MetricCard label={`Margin (${percent(totals.margin_percent)})`} value={formatCurrency(totals.margin)} tone="primary" />
            </div>

            <div className="card card--flush">
                <table className="table sales-tax">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th className="table__cell--end">Invoiced</th>
                            <th className="table__cell--end">Paid</th>
                            <th className="table__cell--end">Cost</th>
                            <th className="table__cell--end">Margin</th>
                            <th aria-label="Months" />
                        </tr>
                    </thead>
                    <tbody>
                        {report.clients.length === 0 && report.unassigned === 0 && (
                            <tr><td colSpan={6} className="table__cell--muted">No hosting invoices or split hosting costs in {report.year}.</td></tr>
                        )}
                        {report.clients.map((client) => {
                            const isOpen = open === client.id;
                            return (
                                <Fragment key={client.id}>
                                    <tr onClick={() => setOpen(isOpen ? null : client.id)} className="table__row--link">
                                        <td className="table__cell--strong">{client.name}</td>
                                        <td className="table__cell--end table__cell--numeric table__cell--strong">{formatCurrency(client.invoiced)}</td>
                                        <td className="table__cell--end table__cell--numeric table__cell--muted">{formatCurrency(client.paid)}</td>
                                        <td className="table__cell--end table__cell--numeric">{formatCurrency(client.cost)}</td>
                                        <td className={`table__cell--end table__cell--numeric${client.margin < 0 ? ' table__cell--negative' : ''}`}>
                                            {formatCurrency(client.margin)} <span className="table__note">{percent(client.margin_percent)}</span>
                                        </td>
                                        <td className="table__cell--end">
                                            <button type="button" aria-expanded={isOpen} aria-label={`${isOpen ? 'Hide' : 'Show'} ${client.name} by month`} className="row-action">
                                                {isOpen ? <CaretDown size={14} weight="bold" /> : <CaretRight size={14} weight="bold" />}
                                            </button>
                                        </td>
                                    </tr>
                                    {isOpen && client.months.map((m) => (
                                        <tr key={m.month} className="sales-tax__payment">
                                            <td>{MONTHS[m.month - 1]}</td>
                                            <td className="table__cell--end table__cell--numeric">{m.invoiced > 0 ? formatCurrency(m.invoiced) : '—'}</td>
                                            <td />
                                            <td className="table__cell--end table__cell--numeric">{m.cost > 0 ? formatCurrency(m.cost) : '—'}</td>
                                            <td className="table__cell--end table__cell--numeric">{formatCurrency(m.invoiced - m.cost)}</td>
                                            <td />
                                        </tr>
                                    ))}
                                </Fragment>
                            );
                        })}
                        {report.unassigned > 0 && (
                            <tr>
                                <td className="table__cell--strong">
                                    Unassigned
                                    <div className="table__note">Hosting bills not split across clients yet</div>
                                </td>
                                <td />
                                <td />
                                <td className="table__cell--end table__cell--numeric">{formatCurrency(report.unassigned)}</td>
                                <td className="table__cell--end table__cell--numeric table__cell--negative">{formatCurrency(-report.unassigned)}</td>
                                <td />
                            </tr>
                        )}
                    </tbody>
                    <tfoot>
                        <tr className="sales-tax__total">
                            <td>Total {report.year}</td>
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.invoiced)}</td>
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.paid)}</td>
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.cost)}</td>
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(totals.margin)}</td>
                            <td />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </AppLayout>
    );
}

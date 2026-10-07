import { Head } from '@inertiajs/react';
import { DownloadSimple } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Badge from '../../Components/Badge';
import EmptyState from '../../Components/EmptyState';
import PageHeader from '../../Components/PageHeader';
import ReportDates from '../../Components/ledger/ReportDates';
import { formatCurrency, formatDate } from '../../lib/format';

const money = (cents) => (cents ? formatCurrency(cents / 100) : '');

// Every account's balance on a day, in a debit or a credit column; the
// totals agree when the books do. Income and expenses are this year's;
// earlier years' profit is in Retained Earnings. Built by
// App\Services\LedgerReports\TrialBalance.
export default function TrialBalance({ report }) {
    return (
        <AppLayout>
            <Head title="Trial balance" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="Trial balance"
                subtitle={`As of ${formatDate(report.as_of)}`}
                actions={<a href={`/bookkeeping/ledger/trial-balance.csv?to=${report.as_of}`} className="btn btn--secondary"><DownloadSimple /> Download CSV</a>}
            />
            <ReportDates path="/bookkeeping/ledger/trial-balance" to={report.as_of} asOf />

            <div className="card card--flush page-section">
                {report.rows.length === 0 ? (
                    <EmptyState text="Nothing posted by this date." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Account</th>
                                <th className="table__cell--end">Debit</th>
                                <th className="table__cell--end">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.rows.map((row) => (
                                <tr key={row.account.id}>
                                    <td className="table__cell--muted u-tabular-nums">{row.account.code}</td>
                                    <td>{row.account.name}</td>
                                    <td className="table__cell--end table__cell--numeric">{money(row.debit)}</td>
                                    <td className="table__cell--end table__cell--numeric">{money(row.credit)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td />
                                <td className="table__cell--strong">
                                    <span className="table__group">
                                        Total
                                        {report.balanced ? <Badge tone="success" label="Balanced" /> : <Badge tone="danger" label="Out of balance" />}
                                    </span>
                                </td>
                                <td className="table__cell--end table__cell--numeric table__cell--strong">{formatCurrency(report.totals.debit / 100)}</td>
                                <td className="table__cell--end table__cell--numeric table__cell--strong">{formatCurrency(report.totals.credit / 100)}</td>
                            </tr>
                        </tfoot>
                    </table>
                )}
            </div>
            {report.prior_years_profit !== 0 && (
                <p className="form-hint">Retained Earnings includes {formatCurrency(report.prior_years_profit / 100)} of profit from before {report.as_of.slice(0, 4)}.</p>
            )}
        </AppLayout>
    );
}

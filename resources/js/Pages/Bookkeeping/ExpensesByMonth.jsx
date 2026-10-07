import { Head } from '@inertiajs/react';
import { DownloadSimple } from '@phosphor-icons/react';
import { Fragment, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import MetricCard from '../../Components/MetricCard';
import PageHeader from '../../Components/PageHeader';
import ReportDates from '../../Components/ledger/ReportDates';
import StackedMonthChart from '../../Components/StackedMonthChart';
import Toggle from '../../Components/Toggle';
import { formatCurrency, formatDate } from '../../lib/format';

const money = (cents) => formatCurrency(cents / 100);
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const monthLabel = (ym) => `${MONTHS[Number(ym.slice(5)) - 1]} ’${ym.slice(2, 4)}`;

// What it costs to run the studio, month by month, in groups (rent,
// utilities, software, office...). Operating expenses only -- client
// media, printing and hosting are cost of revenue, paid for by the
// clients they're billed to. Payroll is left out unless it's switched
// on; it dwarfs the rest. Built by
// App\Services\LedgerReports\ExpensesByMonth.
export default function ExpensesByMonth({ report }) {
    const [payroll, setPayroll] = useState(false);
    const groups = report.groups.filter((g) => g.total !== 0 && (payroll || g.key !== 'payroll'));
    const months = report.months;
    const sumMonth = (ym) => groups.reduce((sum, g) => sum + g.months[ym], 0);
    const total = groups.reduce((sum, g) => sum + g.total, 0);
    // The month in progress isn't over, so the average is of whole months.
    const whole = months.length > 1 && report.to >= new Date().toISOString().slice(0, 10) ? months.slice(0, -1) : months;
    const average = whole.length ? whole.reduce((sum, ym) => sum + sumMonth(ym), 0) / whole.length : 0;
    const biggest = [...groups].sort((a, b) => b.total - a.total)[0];
    const query = `from=${report.from}&to=${report.to}`;

    // Colors stay with their group whether payroll is shown or not.
    const series = groups.map((g) => ({
        key: g.key,
        label: g.label,
        color: report.groups.findIndex((r) => r.key === g.key) + 1,
        values: Object.fromEntries(Object.entries(g.months).map(([ym, cents]) => [ym, cents / 100])),
    }));

    return (
        <AppLayout>
            <Head title="Expenses by month" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="Expenses by month"
                subtitle={`From the ledger, ${formatDate(report.from)} – ${formatDate(report.to)}`}
                actions={<a href={`/bookkeeping/ledger/expenses-by-month.csv?${query}`} className="btn btn--secondary"><DownloadSimple /> Download CSV</a>}
            />
            <ReportDates path="/bookkeeping/ledger/expenses-by-month" from={report.from} to={report.to} />

            <div className="expenses-by-month__options">
                <Toggle checked={payroll} onChange={setPayroll} label="Include payroll & benefits" />
            </div>

            <div className="metric-grid">
                <MetricCard label={whole.length === months.length ? 'Average a month' : 'Average a month (whole months)'} value={money(average)} tone="primary" />
                <MetricCard label="Total" value={money(total)} />
                <MetricCard label="Largest group" value={biggest ? `${biggest.label}, ${money(biggest.total)}` : '—'} />
            </div>

            {groups.length === 0 ? (
                <div className="card card--flush page-section"><EmptyState text="No operating expenses in these dates." /></div>
            ) : (
                <>
                    <StackedMonthChart
                        months={months}
                        series={series}
                        title={payroll ? 'Operating expenses' : 'Operating expenses, without payroll'}
                        ariaLabel="Operating expenses by month, stacked by group"
                    />

                    <div className="card card--flush page-section expenses-by-month__table">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Group</th>
                                    {months.map((ym) => <th key={ym} className="table__cell--end">{monthLabel(ym)}</th>)}
                                    <th className="table__cell--end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {groups.map((g) => (
                                    <Fragment key={g.key}>
                                        <tr className="expenses-by-month__group">
                                            <td className="table__cell--strong">
                                                <span className={`year-chart__key year-chart__key--series-${series.find((s) => s.key === g.key).color}`} />
                                                {g.label}
                                            </td>
                                            {months.map((ym) => <td key={ym} className="table__cell--end table__cell--numeric table__cell--strong">{money(g.months[ym])}</td>)}
                                            <td className="table__cell--end table__cell--numeric table__cell--strong">{money(g.total)}</td>
                                        </tr>
                                        {g.accounts.map((line) => (
                                            <tr key={line.account.id} className="expenses-by-month__account">
                                                <td className="table__cell--muted">{line.account.name}</td>
                                                {months.map((ym) => <td key={ym} className="table__cell--end table__cell--numeric table__cell--muted">{line.months[ym] ? money(line.months[ym]) : '–'}</td>)}
                                                <td className="table__cell--end table__cell--numeric table__cell--muted">{money(line.total)}</td>
                                            </tr>
                                        ))}
                                    </Fragment>
                                ))}
                                <tr className="expenses-by-month__total">
                                    <td className="table__cell--strong">Total</td>
                                    {months.map((ym) => <td key={ym} className="table__cell--end table__cell--numeric table__cell--strong">{money(sumMonth(ym))}</td>)}
                                    <td className="table__cell--end table__cell--numeric table__cell--strong">{money(total)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p className="form-hint">Cash basis, as the expenses were paid. Client media, printing and hosting are left out: they're cost of revenue, billed back to clients (see Profit &amp; loss).</p>
                </>
            )}
        </AppLayout>
    );
}

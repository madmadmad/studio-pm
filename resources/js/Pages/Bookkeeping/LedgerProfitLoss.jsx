import { Head } from '@inertiajs/react';
import { DownloadSimple } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import PageHeader from '../../Components/PageHeader';
import ReportDates from '../../Components/ledger/ReportDates';
import { formatCurrency, formatDate } from '../../lib/format';

const money = (cents) => formatCurrency(cents / 100);

function Section({ title, lines, total, totalLabel }) {
    return (
        <div className="statement__section">
            <div className="section-label section-label--ruled">{title}</div>
            {lines.length === 0 ? (
                <div className="statement__line statement__line--empty">None in these dates</div>
            ) : lines.map((line) => (
                <div key={line.account.id} className="statement__line">
                    <span><span className="statement__code">{line.account.code}</span>{line.account.name}</span>
                    <span className="statement__amount">{money(line.amount)}</span>
                </div>
            ))}
            <div className="statement__line statement__line--total">
                <span>{totalLabel}</span>
                <span className="statement__amount">{money(total)}</span>
            </div>
        </div>
    );
}

// Profit & loss from the ledger, for any dates: income, less cost of
// revenue for gross profit (what hosting, printing and client media earn
// over what they cost), less operating expenses for net profit. Built by
// App\Services\LedgerReports\ProfitAndLoss.
export default function LedgerProfitLoss({ report }) {
    const { totals } = report;
    const query = `from=${report.from}&to=${report.to}`;

    return (
        <AppLayout>
            <Head title="Profit & loss" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="Profit & loss"
                subtitle={`From the ledger, ${formatDate(report.from)} – ${formatDate(report.to)}`}
                actions={<a href={`/bookkeeping/ledger/profit-loss.csv?${query}`} className="btn btn--secondary"><DownloadSimple /> Download CSV</a>}
            />
            <ReportDates path="/bookkeeping/ledger/profit-loss" from={report.from} to={report.to} />

            <div className="metric-grid">
                <MetricCard label="Income" value={money(totals.income)} />
                <MetricCard label="Gross profit" value={money(totals.gross_profit)} negative={totals.gross_profit < 0} />
                <MetricCard label="Net profit" value={money(totals.net)} negative={totals.net < 0} tone="primary" />
            </div>

            <div className="card card--padded statement">
                <Section title="Income" lines={report.income} total={totals.income} totalLabel="Total income" />
                <Section title="Cost of revenue" lines={report.cost_of_revenue} total={totals.cost_of_revenue} totalLabel="Total cost of revenue" />
                <div className="statement__line statement__line--key statement__section">
                    <span>Gross profit</span>
                    <span className="statement__amount">{money(totals.gross_profit)}</span>
                </div>
                <Section title="Operating expenses" lines={report.expenses} total={totals.expenses} totalLabel="Total operating expenses" />
                <div className="statement__line statement__line--key">
                    <span>Net profit</span>
                    <span className="statement__amount">{money(totals.net)}</span>
                </div>
                <p className="form-hint statement__note">Cash basis: income as it was paid, expenses as they were incurred. Sales tax collected is owed to the state, so it isn't income.</p>
            </div>
        </AppLayout>
    );
}

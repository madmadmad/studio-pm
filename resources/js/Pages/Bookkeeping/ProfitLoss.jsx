import { Head, Link } from '@inertiajs/react';
import { DownloadSimple } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import PageHeader from '../../Components/PageHeader';
import { formatCurrency } from '../../lib/format';

// One side of the statement: its lines, then their total.
function Section({ title, lines, total, totalLabel }) {
    return (
        <div className="profit-loss__section">
            <div className="section-label section-label--ruled">{title}</div>
            {lines.length === 0 ? (
                <div className="profit-loss__line profit-loss__line--empty">None this year</div>
            ) : lines.map((line) => (
                <div key={line.name} className="profit-loss__line">
                    <span>{line.name}</span>
                    <span className="profit-loss__amount">{formatCurrency(line.amount)}</span>
                </div>
            ))}
            <div className="profit-loss__line profit-loss__line--total">
                <span>{totalLabel}</span>
                <span className="profit-loss__amount">{formatCurrency(total)}</span>
            </div>
        </div>
    );
}

// The year's profit & loss statement: income by what it was for, expenses
// by category, and the net. Cash basis, like the Bookkeeping cards. Built
// by App\Services\ProfitLossReport.
export default function ProfitLoss({ report, years }) {
    const { totals } = report;

    return (
        <AppLayout>
            <Head title="Profit & loss" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="Profit & loss statement"
                actions={(
                    <a href={`/bookkeeping/profit-loss.csv?year=${report.year}`} className="btn btn--secondary">
                        <DownloadSimple /> Download CSV
                    </a>
                )}
            />

            <div className="filter-bar">
                <div className="filter-bar__pills">
                    {years.map((year) => (
                        <Link
                            key={year}
                            href={`/bookkeeping/profit-loss?year=${year}`}
                            preserveScroll
                            className={`filter-bar__pill${year === report.year ? ' filter-bar__pill--active' : ''}`}
                        >
                            {year}
                        </Link>
                    ))}
                </div>
            </div>

            <div className="metric-grid">
                <MetricCard label={`Income in ${report.year}`} value={formatCurrency(totals.income)} />
                <MetricCard label="Expenses" value={formatCurrency(totals.expenses)} />
                <MetricCard
                    label={`Net profit${totals.margin_percent !== null ? ` (${totals.margin_percent}%)` : ''}`}
                    value={formatCurrency(totals.net)}
                    tone="primary"
                />
            </div>

            <div className="card card--padded profit-loss">
                <Section title="Income" lines={report.income} total={totals.income} totalLabel="Total income" />
                <Section title="Expenses" lines={report.expenses} total={totals.expenses} totalLabel="Total expenses" />
                <div className="profit-loss__line profit-loss__line--net">
                    <span>Net profit</span>
                    <span className="profit-loss__amount">{formatCurrency(totals.net)}</span>
                </div>
                <p className="form-hint profit-loss__note">
                    Income is what was paid in {report.year}, less {formatCurrency(report.sales_tax_collected)} of sales tax collected for the state.
                </p>
            </div>
        </AppLayout>
    );
}

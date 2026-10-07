import { Head } from '@inertiajs/react';
import { DownloadSimple } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import PageHeader from '../../Components/PageHeader';
import ReportDates from '../../Components/ledger/ReportDates';
import { formatCurrency, formatDate } from '../../lib/format';

const money = (cents) => formatCurrency(cents / 100);

function Section({ title, lines, total }) {
    return (
        <div className="statement__section">
            <div className="section-label section-label--ruled">{title}</div>
            {lines.length === 0 ? (
                <div className="statement__line statement__line--empty">None</div>
            ) : lines.map((line) => (
                <div key={line.account.id ?? line.account.name} className="statement__line">
                    <span>{line.account.code && <span className="statement__code">{line.account.code}</span>}{line.account.name}</span>
                    <span className="statement__amount">{money(line.amount)}</span>
                </div>
            ))}
            <div className="statement__line statement__line--total">
                <span>Total {title.toLowerCase()}</span>
                <span className="statement__amount">{money(total)}</span>
            </div>
        </div>
    );
}

// The balance sheet on a day: what the studio has, what it owes, and the
// equity between -- shareholder money in and out, earlier years' profit
// (Retained Earnings) and this year's so far. Built by
// App\Services\LedgerReports\BalanceSheet.
export default function BalanceSheet({ report }) {
    const { totals } = report;

    return (
        <AppLayout>
            <Head title="Balance sheet" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="Balance sheet"
                subtitle={`As of ${formatDate(report.as_of)}`}
                actions={<a href={`/bookkeeping/ledger/balance-sheet.csv?to=${report.as_of}`} className="btn btn--secondary"><DownloadSimple /> Download CSV</a>}
            />
            <ReportDates path="/bookkeeping/ledger/balance-sheet" to={report.as_of} asOf />

            <div className="metric-grid">
                <MetricCard label="Assets" value={money(totals.assets)} />
                <MetricCard label="Liabilities" value={money(totals.liabilities)} tone="muted" />
                <MetricCard label="Equity" value={money(totals.equity)} tone="primary" negative={totals.equity < 0} />
            </div>

            {!report.balanced && (
                <div className="form-message form-message--error page-section">
                    Assets and liabilities plus equity differ by {money(Math.abs(totals.assets - totals.liabilities_and_equity))}. Something posted is off.
                </div>
            )}

            <div className="card card--padded statement">
                <Section title="Assets" lines={report.assets} total={totals.assets} />
                <Section title="Liabilities" lines={report.liabilities} total={totals.liabilities} />
                <Section title="Equity" lines={report.equity} total={totals.equity} />
                <div className="statement__line statement__line--key">
                    <span>Liabilities and equity</span>
                    <span className="statement__amount">{money(totals.liabilities_and_equity)}</span>
                </div>
            </div>
        </AppLayout>
    );
}

import { Head, router } from '@inertiajs/react';
import { DownloadSimple } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import PageHeader from '../../Components/PageHeader';
import AccountSelect from '../../Components/ledger/AccountSelect';
import ReportDates from '../../Components/ledger/ReportDates';
import { formatCurrency, formatDate } from '../../lib/format';

const money = (cents) => (cents ? formatCurrency(cents / 100) : '');

// One account's lines between the dates: the balance before, each line
// with the running balance, and the balance after.
function AccountLedger({ account, from, to }) {
    return (
        <div>
            <div className="section-label section-label--ruled">{account.account.code} {account.account.name}</div>
            <div className="card card--flush page-section">
                <table className="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>No.</th>
                            <th>Memo</th>
                            <th>Client</th>
                            <th className="table__cell--end">Debit</th>
                            <th className="table__cell--end">Credit</th>
                            <th className="table__cell--end">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td className="table__cell--muted">{formatDate(from)}</td>
                            <td />
                            <td className="table__cell--muted">Opening balance</td>
                            <td />
                            <td />
                            <td />
                            <td className="table__cell--end table__cell--numeric">{formatCurrency(account.opening / 100)}</td>
                        </tr>
                        {account.lines.map((line) => (
                            <tr key={line.id}>
                                <td>{formatDate(line.date)}</td>
                                <td className="table__cell--muted u-tabular-nums">{line.entry_number}</td>
                                <td>
                                    {line.memo}
                                    {line.description && <div className="table__meta">{line.description}</div>}
                                </td>
                                <td className="table__cell--muted">{line.client}</td>
                                <td className="table__cell--end table__cell--numeric">{money(line.debit)}</td>
                                <td className="table__cell--end table__cell--numeric">{money(line.credit)}</td>
                                <td className="table__cell--end table__cell--numeric">{formatCurrency(line.balance / 100)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td className="table__cell--muted">{formatDate(to)}</td>
                            <td />
                            <td className="table__cell--strong">Closing balance</td>
                            <td />
                            <td className="table__cell--end table__cell--numeric table__cell--strong">{money(account.debits)}</td>
                            <td className="table__cell--end table__cell--numeric table__cell--strong">{money(account.credits)}</td>
                            <td className="table__cell--end table__cell--numeric table__cell--strong">{formatCurrency(account.closing / 100)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

// Every line posted to each account between two dates, with running
// balances read the way each account normally runs (cash up, the card
// owed up). One account, or all of them. Built by
// App\Services\LedgerReports\GeneralLedger.
export default function GeneralLedger({ report, accounts }) {
    const path = '/bookkeeping/ledger/general-ledger';
    const extra = report.account_id ? { account: report.account_id } : {};
    const query = new URLSearchParams({ from: report.from, to: report.to, ...extra }).toString();

    function pickAccount(id) {
        router.get(path, { from: report.from, to: report.to, ...(id ? { account: id } : {}) }, { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="General ledger" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="General ledger"
                subtitle={`${formatDate(report.from)} – ${formatDate(report.to)}`}
                actions={<a href={`${path}.csv?${query}`} className="btn btn--secondary"><DownloadSimple /> Download CSV</a>}
            />
            <ReportDates path={path} from={report.from} to={report.to} extra={extra} />
            <div className="page-section page-section--tight">
                <AccountSelect accounts={accounts} value={report.account_id} blankLabel="All accounts" label="Account" onChange={pickAccount} className="input input--sm input--inline" />
            </div>

            {report.accounts.length === 0
                ? <div className="card card--flush"><EmptyState text="Nothing posted in these dates." /></div>
                : report.accounts.map((account) => <AccountLedger key={account.account.id} account={account} from={report.from} to={report.to} />)}
        </AppLayout>
    );
}

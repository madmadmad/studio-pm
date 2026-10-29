import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import CurrencyInput from '../../Components/CurrencyInput';
import PageHeader from '../../Components/PageHeader';
import { api } from '../../lib/api';
import { formatCurrency, formatDate, todayInAppTimezone } from '../../lib/format';
import { useReadOnly } from '../../lib/permissions';

// Bookkeeping > Reconcile: checking and the card, each reconciled against
// its monthly statement (App\Services\Reconciliation\Reconciler). Per
// account: where the last statement left off, the one in progress or a
// form to start the next -- its date, ending balance and, if there is one,
// its CSV export to tick the matches -- and the statements so far.
export default function Reconcile({ accounts }) {
    return (
        <AppLayout>
            <Head title="Reconcile" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="Reconcile"
                subtitle="Each month, tick off the statement against the books until they agree."
            />
            <div className="reconcile">
                {accounts.map((account) => <AccountPanel key={account.id} account={account} />)}
            </div>
        </AppLayout>
    );
}

function AccountPanel({ account }) {
    const readOnly = useReadOnly();
    const [form, setForm] = useState({ statement_date: todayInAppTimezone(), ending_balance: '' });
    const [file, setFile] = useState(null);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState('');
    const balanceLabel = account.is_card ? 'Owed at statement end' : 'Balance at statement end';

    async function start(e) {
        e.preventDefault();
        setStarting(true);
        setError('');
        const body = new FormData();
        body.append('account_id', account.id);
        body.append('statement_date', form.statement_date);
        body.append('ending_balance', form.ending_balance);
        if (file) body.append('statement', file);
        try {
            const { id } = await api.postForm('/api/reconciliations', body);
            router.visit(`/bookkeeping/reconcile/${id}`);
        } catch (err) {
            setError(err.message || 'Couldn’t start it.');
            setStarting(false);
        }
    }

    return (
        <section className="card card--padded reconcile__account">
            <div className="reconcile__head">
                <h2 className="reconcile__name">{account.name}</h2>
                <span className="reconcile__last">
                    {account.last
                        ? <>Reconciled through {formatDate(account.last.date)} · {formatCurrency(account.last.ending_cents / 100)}</>
                        : 'Not reconciled yet'}
                </span>
            </div>

            {account.open_id ? (
                <p className="reconcile__open">
                    A statement is in progress. <Link href={`/bookkeeping/reconcile/${account.open_id}`} className="link link--inline">Continue reconciling</Link>
                </p>
            ) : !readOnly && (
                <form onSubmit={start} className="reconcile__start">
                    <label className="reconcile__field">
                        <span className="section-label section-label--tight">Statement date</span>
                        <input type="date" required value={form.statement_date} max={todayInAppTimezone()} onChange={(e) => setForm({ ...form, statement_date: e.target.value })} className="input input--sm" />
                    </label>
                    <label className="reconcile__field">
                        <span className="section-label section-label--tight">{balanceLabel}</span>
                        <CurrencyInput required value={form.ending_balance} onChange={(ending_balance) => setForm({ ...form, ending_balance })} className="input input--sm" />
                    </label>
                    <label className="reconcile__field reconcile__field--wide">
                        <span className="section-label section-label--tight">Statement export (CSV, optional)</span>
                        <input type="file" accept=".csv,text/csv" onChange={(e) => setFile(e.target.files[0] ?? null)} className="input input--sm" />
                    </label>
                    <Button type="submit" variant="primary" disabled={starting || form.ending_balance === ''}>{starting ? 'Starting…' : 'Start reconciling'}</Button>
                    {error && <div className="form-message form-message--error reconcile__error">{error}</div>}
                </form>
            )}

            {account.history.length > 0 && (
                <table className="table reconcile__history">
                    <thead>
                        <tr>
                            <th>Statement</th>
                            <th className="table__cell--end">Ending balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {account.history.map((r) => (
                            <tr key={r.id} onClick={() => router.visit(`/bookkeeping/reconcile/${r.id}`)} className="table__row--link">
                                <td className="table__cell--strong">{formatDate(r.date)}</td>
                                <td className="table__cell--end table__cell--numeric">{formatCurrency(r.ending_cents / 100)}</td>
                                <td className="table__cell--muted">{r.completed_at ? `Reconciled${r.completed_by ? ` by ${r.completed_by}` : ''}` : 'In progress'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </section>
    );
}

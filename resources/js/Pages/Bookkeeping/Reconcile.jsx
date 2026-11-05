import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle, WarningCircle } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import CurrencyInput from '../../Components/CurrencyInput';
import PageHeader from '../../Components/PageHeader';
import { api } from '../../lib/api';
import { formatCurrency, formatDate, todayInAppTimezone, formatShortDate } from '../../lib/format';
import { useReadOnly } from '../../lib/permissions';

// Bookkeeping > Reconcile: checking and the card, each reconciled against
// its monthly statement (App\Services\Reconciliation\Reconciler). Per
// account: where the last statement left off, the one in progress or a
// form to start the next -- its PDF, read and checked before starting, or
// its date, ending balance and, if there is one, its CSV export to tick
// the matches -- and the statements so far.
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

const money = (cents) => formatCurrency(cents / 100);

// The day after a date, as YYYY-MM-DD.
function dayAfter(date) {
    const d = new Date(`${date}T12:00:00`);
    d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
}

// One check on a statement read from its PDF: a tick, or a warning to look
// at before going on.
function Check({ ok, children }) {
    const Icon = ok ? CheckCircle : WarningCircle;
    return (
        <li className={`reconcile__check${ok ? '' : ' reconcile__check--warn'}`}>
            <Icon weight="fill" aria-label={ok ? 'Checks out' : 'Look at this'} />
            <span>{children}</span>
        </li>
    );
}

// What the statement's PDF says, before starting: the account and its
// figures, that every line on it was read (the statement's own counts and
// totals), and that it starts where the last reconciled statement ended.
function StatementPreview({ statement: s, isCard }) {
    const inLabel = isCard ? 'payments & credits' : 'credits';
    const outLabel = isCard ? 'charges' : 'debits & checks';
    const startsOk = s.beginning_cents === s.cleared_cents;
    const followsOn = !s.last_date || s.period_start === dayAfter(s.last_date);

    return (
        <div className="reconcile__preview">
            <div className="reconcile__preview-head">
                {s.bank} statement · account ending {s.account_last4}
            </div>
            <ul className="reconcile__checks">
                <Check ok>Ends {formatShortDate(s.statement_date)} {isCard ? 'owing' : 'at'} {money(s.ending_cents)}</Check>
                <Check ok>
                    All {s.rows} lines read: {s.totals.in_count} {inLabel} {money(s.totals.in_cents)} · {s.totals.out_count} {outLabel} {money(s.totals.out_cents)}
                </Check>
                {startsOk ? (
                    <Check ok>Starts at {money(s.beginning_cents)}, where {s.last_date ? `the ${formatShortDate(s.last_date)} statement` : 'the books'} left off</Check>
                ) : (
                    <Check ok={false}>
                        {s.last_date
                            ? <>Starts at {money(s.beginning_cents)}, but the {formatShortDate(s.last_date)} statement ended at {money(s.cleared_cents)}, off by {money(Math.abs(s.beginning_cents - s.cleared_cents))} before anything’s ticked.</>
                            : <>Starts at {money(s.beginning_cents)}, but nothing before it is reconciled here, so the books start at {money(s.cleared_cents)}. Reconcile the months before this one first.</>}
                    </Check>
                )}
                {!followsOn && (
                    <Check ok={false}>It covers from {formatShortDate(s.period_start)}, but the last reconciled statement ended {formatShortDate(s.last_date)}. Is a statement missing?</Check>
                )}
            </ul>
        </div>
    );
}

function AccountPanel({ account }) {
    const readOnly = useReadOnly();
    const [form, setForm] = useState({ statement_date: todayInAppTimezone(), ending_balance: '' });
    const [file, setFile] = useState(null);
    // A PDF, read: its figures (StatementPreview), or why it couldn't be.
    const [preview, setPreview] = useState(null);
    const [reading, setReading] = useState(false);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState('');
    const balanceLabel = account.is_card ? 'Owed at statement end' : 'Balance at statement end';
    const isPdf = Boolean(file && /\.pdf$/i.test(file.name));

    async function choose(chosen) {
        setFile(chosen);
        setPreview(null);
        setError('');
        if (!chosen || !/\.pdf$/i.test(chosen.name)) return;
        setReading(true);
        const body = new FormData();
        body.append('account_id', account.id);
        body.append('statement_pdf', chosen);
        try {
            setPreview(await api.postForm('/api/reconciliations/preview', body));
        } catch (err) {
            setError(err.message || 'Couldn’t read that PDF.');
        } finally {
            setReading(false);
        }
    }

    async function start(e) {
        e.preventDefault();
        setStarting(true);
        setError('');
        const body = new FormData();
        body.append('account_id', account.id);
        if (isPdf) {
            body.append('statement_pdf', file);
        } else {
            body.append('statement_date', form.statement_date);
            body.append('ending_balance', form.ending_balance);
            if (file) body.append('statement', file);
        }
        try {
            const { id } = await api.postForm('/api/reconciliations', body);
            router.visit(`/bookkeeping/reconcile/${id}`);
        } catch (err) {
            setError(err.message || 'Couldn’t start it.');
            setStarting(false);
        }
    }

    const ready = isPdf ? Boolean(preview) : form.ending_balance !== '';

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
                    <label className="reconcile__field reconcile__field--wide">
                        <span className="section-label section-label--tight">Statement (PDF, or the CSV export)</span>
                        <input type="file" accept=".pdf,application/pdf,.csv,text/csv" onChange={(e) => choose(e.target.files[0] ?? null)} className="input input--sm" />
                    </label>
                    {!isPdf && (
                        <>
                            <label className="reconcile__field">
                                <span className="section-label section-label--tight">Statement date</span>
                                <input type="date" required value={form.statement_date} max={todayInAppTimezone()} onChange={(e) => setForm({ ...form, statement_date: e.target.value })} className="input input--sm" />
                            </label>
                            <label className="reconcile__field">
                                <span className="section-label section-label--tight">{balanceLabel}</span>
                                <CurrencyInput required value={form.ending_balance} onChange={(ending_balance) => setForm({ ...form, ending_balance })} className="input input--sm" />
                            </label>
                        </>
                    )}
                    <Button type="submit" variant="primary" disabled={starting || reading || !ready}>
                        {reading ? 'Reading…' : starting ? 'Starting…' : 'Start reconciling'}
                    </Button>
                    {preview && <StatementPreview statement={preview} isCard={account.is_card} />}
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
                                <td className="table__cell--strong">{formatShortDate(r.date)}</td>
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

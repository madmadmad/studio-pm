import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { DownloadSimple } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import MetricCard from '../../Components/MetricCard';
import PageHeader from '../../Components/PageHeader';
import { api } from '../../lib/api';
import { formatCurrency, formatDate } from '../../lib/format';
import { useReadOnly } from '../../lib/permissions';

const money = (cents) => formatCurrency(cents / 100);

// One statement being reconciled (or once reconciled). The account's
// entries up to the statement date that no earlier statement covers, each
// ticked once it's on this one -- the export's matches already ticked --
// and the running difference from the statement's ending balance. Lines on
// the export the books don't have can be added from here. It finishes at a
// difference of $0.00; the latest finished one can be reopened to fix.
export default function Reconciliation({ reconciliation: r, summary: initialSummary, lines: initialLines, missing, has_export: hasExport }) {
    const readOnly = useReadOnly();
    const editable = !readOnly && !r.completed_at;
    const [lines, setLines] = useState(initialLines);
    const [summary, setSummary] = useState(initialSummary);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [confirmCancel, setConfirmCancel] = useState(false);
    const inLabel = r.is_card ? 'Payments & credits' : 'Deposits';
    const outLabel = r.is_card ? 'Charges' : 'Payments out';

    async function run(action) {
        setBusy(true);
        setError('');
        try {
            await action();
        } catch (err) {
            setError(err.message || 'That didn’t work.');
        } finally {
            setBusy(false);
        }
    }

    async function toggle(line) {
        const cleared = !line.cleared;
        setLines((current) => current.map((l) => (l.id === line.id ? { ...l, cleared } : l)));
        try {
            setSummary(await api.post(`/api/reconciliations/${r.id}/lines/${line.id}`, { cleared }));
        } catch (err) {
            setLines((current) => current.map((l) => (l.id === line.id ? { ...l, cleared: !cleared } : l)));
            setError(err.message || 'Couldn’t change that.');
        }
    }

    const reload = () => router.reload();
    const finish = () => run(async () => { await api.post(`/api/reconciliations/${r.id}/finish`); reload(); });
    const reopen = () => run(async () => { await api.post(`/api/reconciliations/${r.id}/reopen`); reload(); });
    const cancel = () => run(async () => { await api.delete(`/api/reconciliations/${r.id}`); router.visit('/bookkeeping/reconcile'); });
    const add = (row) => run(async () => { await api.post(`/api/reconciliations/${r.id}/rows/${row.index}/add`); reload(); });

    const difference = summary.difference_cents;
    const section = (title, rows) => rows.length > 0 && (
        <div className="card card--flush page-section">
            <table className="table reconcile__lines">
                <thead>
                    <tr>
                        <th className="reconcile__tick" aria-label="Cleared" />
                        <th>{title}</th>
                        <th>Date</th>
                        <th className="table__cell--end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((line) => (
                        <tr key={line.id} className={line.cleared ? 'reconcile__line--cleared' : undefined}>
                            <td className="reconcile__tick">
                                <input
                                    type="checkbox"
                                    checked={line.cleared}
                                    disabled={!editable}
                                    onChange={() => toggle(line)}
                                    aria-label={`Cleared: ${line.memo}`}
                                />
                            </td>
                            <td>{line.memo} <span className="table__cell--muted">#{line.entry_number}</span></td>
                            <td className="table__cell--muted">{formatDate(line.date)}</td>
                            <td className="table__cell--end table__cell--numeric">{money(Math.abs(line.cents))}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );

    return (
        <AppLayout>
            <Head title={`Reconcile ${r.account}`} />
            <PageHeader
                back={{ href: '/bookkeeping/reconcile', label: 'Reconcile' }}
                title={`${r.account}, ${formatDate(r.statement_date)}`}
                subtitle={r.completed_at
                    ? `Reconciled ${formatDate(r.completed_at)}${r.completed_by ? ` by ${r.completed_by}` : ''}.`
                    : 'Tick each entry that’s on the statement. It’s done when the difference is $0.00.'}
                actions={(
                    <a href={`/bookkeeping/reconcile/${r.id}.csv`} className="btn btn--secondary">
                        <DownloadSimple /> Report (CSV)
                    </a>
                )}
            />

            <div className="metric-grid">
                <MetricCard label={r.is_card ? 'Owed at last statement' : 'Last statement balance'} value={money(summary.starting_cents)} />
                <MetricCard label="Cleared balance" value={money(summary.cleared_balance_cents)} />
                <MetricCard label={r.is_card ? 'Owed per statement' : 'Statement balance'} value={money(summary.ending_cents)} />
                <MetricCard label="Difference" value={money(difference)} tone={difference === 0 ? 'success' : 'danger'} />
            </div>

            {error && <div className="form-message form-message--error page-section">{error}</div>}

            {editable && (
                <div className="reconcile__actions page-section">
                    <Button variant="confirm" onClick={finish} disabled={busy || difference !== 0}>Finish reconciliation</Button>
                    {confirmCancel ? (
                        <span className="reconcile__confirm">
                            Drop this statement and untick everything?{' '}
                            <Button variant="link-accent" onClick={cancel} disabled={busy}>Drop it</Button>{' '}
                            <Button variant="link" onClick={() => setConfirmCancel(false)}>Keep it</Button>
                        </span>
                    ) : (
                        <Button variant="link" onClick={() => setConfirmCancel(true)}>Cancel reconciliation</Button>
                    )}
                </div>
            )}
            {!readOnly && r.can_reopen && (
                <div className="reconcile__actions page-section">
                    <Button variant="secondary" onClick={reopen} disabled={busy}>Reopen to fix</Button>
                    <span className="form-hint">Its entries can be changed again until it’s finished.</span>
                </div>
            )}

            {editable && hasExport && missing.length > 0 && (
                <div className="card card--padded page-section">
                    <div className="section-label">On the statement, not in the books</div>
                    <p className="form-hint">Add what’s really missing; it comes in uncategorized, to sort on Expenses. Leave anything that’s a match the dates hid, and tick its entry below instead.</p>
                    <ul className="detail-list">
                        {missing.map((row) => (
                            <li key={row.index} className="detail-list__item detail-list__item--split">
                                <span>{formatDate(row.date)} · {row.description}</span>
                                <span className="reconcile__missing-amount">
                                    <span className="u-tabular-nums">{money(row.cents)}</span>
                                    <Button variant="link-accent" onClick={() => add(row)} disabled={busy}>Add</Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {section(inLabel, lines.filter((l) => l.cents > 0))}
            {section(outLabel, lines.filter((l) => l.cents <= 0))}
            {lines.length === 0 && <p className="form-hint">No entries on this account up to the statement date.</p>}
        </AppLayout>
    );
}

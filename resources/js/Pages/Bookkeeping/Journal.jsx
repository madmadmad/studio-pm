import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CaretRight } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Badge from '../../Components/Badge';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import PageHeader from '../../Components/PageHeader';
import TabBar from '../../Components/TabBar';
import EntryDrawer from '../../Components/ledger/EntryDrawer';
import NewEntryDrawer from '../../Components/ledger/NewEntryDrawer';
import PayoutDrawer from '../../Components/ledger/PayoutDrawer';
import PayrollDrawer from '../../Components/ledger/PayrollDrawer';
import TransferDrawer from '../../Components/ledger/TransferDrawer';
import { api } from '../../lib/api';
import { formatCurrency, formatDate } from '../../lib/format';
import { useRememberedTab } from '../../lib/useRememberedTab';

// The year's entries, newest first. A row opens the entry.
function EntriesTab({ entries, year, years, onOpen }) {
    return (
        <>
            <div className="filter-bar">
                <div className="filter-bar__pills">
                    {years.map((y) => (
                        <Link key={y} href={`/bookkeeping/journal?year=${y}`} preserveScroll className={`filter-bar__pill${y === year ? ' filter-bar__pill--active' : ''}`}>
                            {y}
                        </Link>
                    ))}
                </div>
            </div>
            <div className="card card--flush page-section">
                {entries.length === 0 ? (
                    <EmptyState text={`No entries in ${year}.`} />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Date</th>
                                <th>Memo</th>
                                <th>From</th>
                                <th className="table__cell--end">Amount</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {entries.map((entry) => (
                                <tr key={entry.id} onClick={() => onOpen(entry)} className="table__row--link">
                                    <td className="table__cell--muted u-tabular-nums">{entry.entry_number}</td>
                                    <td>{formatDate(entry.entry_date)}</td>
                                    <td className="table__cell--strong">
                                        <span className="table__group">
                                            {entry.memo}
                                            {entry.reverses && <Badge tone="neutral" label="Reversal" />}
                                            {entry.reversed_by && <Badge tone="warning" label="Reversed" />}
                                        </span>
                                    </td>
                                    <td className="table__cell--muted">{entry.source ? `${entry.source.kind}: ${entry.source.label}` : 'By hand'}</td>
                                    <td className="table__cell--end table__cell--numeric">{formatCurrency(entry.total_cents / 100)}</td>
                                    <td className="table__cell--end">
                                        <button type="button" aria-label={`Open entry ${entry.entry_number}`} className="row-action"><CaretRight /></button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </>
    );
}

// Closed stretches of the books: nothing can be dated inside one until
// it's unlocked. Super admin only.
function PeriodsTab({ periods: periodsProp }) {
    const [periods, setPeriods] = useState(periodsProp);
    const [form, setForm] = useState({ starts_on: '', ends_on: '' });
    const [error, setError] = useState('');

    async function lock(e) {
        e.preventDefault();
        setError('');
        try {
            const period = await api.post('/api/accounting-periods', form);
            setPeriods([period, ...periods].sort((a, b) => b.starts_on.localeCompare(a.starts_on)));
            setForm({ starts_on: '', ends_on: '' });
        } catch (err) {
            setError(err.message || 'Could not lock that period.');
        }
    }

    async function unlock(period) {
        if (!confirm(`Unlock ${formatDate(period.starts_on)} – ${formatDate(period.ends_on)}? Entries can be dated in it again.`)) return;
        const updated = await api.post(`/api/accounting-periods/${period.id}/unlock`);
        setPeriods(periods.map((p) => (p.id === updated.id ? updated : p)));
    }

    return (
        <>
            <form onSubmit={lock} className="form-panel page-section">
                <div className="section-label section-label--ruled">Lock a period</div>
                <p className="form-hint">Once the CPA has a period's numbers, lock it so nothing changes underneath them. Unlock it to fix something.</p>
                <div className="inline-form">
                    <input required type="date" aria-label="From" value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })} className="input input--xs" />
                    <input required type="date" aria-label="To" value={form.ends_on} onChange={(e) => setForm({ ...form, ends_on: e.target.value })} className="input input--xs" />
                    <Button type="submit">Lock</Button>
                </div>
                {error && <div className="form-message form-message--error">{error}</div>}
            </form>
            <div className="card card--flush page-section">
                {periods.length === 0 ? (
                    <EmptyState text="No periods locked yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Status</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {periods.map((period) => (
                                <tr key={period.id}>
                                    <td className="table__cell--strong">{formatDate(period.starts_on)} – {formatDate(period.ends_on)}</td>
                                    <td>
                                        {period.locked_at
                                            ? <span className="table__group"><Badge tone="success" label="Locked" />{period.locker && <span className="table__note">by {period.locker.name}</span>}</span>
                                            : <Badge tone="neutral" label="Unlocked" />}
                                    </td>
                                    <td className="table__cell--end">
                                        {period.locked_at && <button type="button" onClick={() => unlock(period)} className="text-action">Unlock</button>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </>
    );
}

const FORMS = {
    entry: NewEntryDrawer,
    transfer: TransferDrawer,
    payout: PayoutDrawer,
    payroll: PayrollDrawer,
};

// Bookkeeping > Journal: every entry in the ledger, the ones the app posts
// for expenses and payments and the ones made here by hand -- a transfer
// (the monthly card payment), a Stripe payout, payroll, or a general
// entry. Built by App\Http\Controllers\Web\LedgerPageController.
export default function Journal({ entries, year, years, accounts, companies, clearingCents, canLock, periods }) {
    const tabs = canLock ? ['Entries', 'Locked periods'] : ['Entries'];
    const [tab, setTab] = useRememberedTab('bookkeeping.journal.tab', tabs);
    const [open, setOpen] = useState(null);
    const [creating, setCreating] = useState(null);
    const Form = creating ? FORMS[creating] : null;

    function refresh() {
        router.reload({ only: ['entries', 'years', 'clearingCents'] });
    }

    return (
        <AppLayout>
            <Head title="Journal" />
            <PageHeader
                back={{ href: '/bookkeeping', label: 'Bookkeeping' }}
                title="Journal"
                actions={(
                    <>
                        <Button variant="secondary" onClick={() => setCreating('transfer')}>Transfer</Button>
                        <Button variant="secondary" onClick={() => setCreating('payout')}>Stripe payout</Button>
                        <Button variant="secondary" onClick={() => setCreating('payroll')}>Payroll</Button>
                        <Button onClick={() => setCreating('entry')}>Journal entry</Button>
                    </>
                )}
            />
            {tabs.length > 1 && <TabBar tabs={tabs} tab={tab} setTab={setTab} />}
            {tab === 'Locked periods' && canLock
                ? <PeriodsTab periods={periods} />
                : <EntriesTab entries={entries} year={year} years={years} onOpen={setOpen} />}

            {open && <EntryDrawer key={open.id} entry={open} onReversed={refresh} onClose={() => setOpen(null)} />}
            {Form && (
                <Form
                    accounts={accounts}
                    companies={companies}
                    clearingCents={clearingCents}
                    onPosted={refresh}
                    onClose={() => setCreating(null)}
                />
            )}
        </AppLayout>
    );
}

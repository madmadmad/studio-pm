import { useState } from 'react';
import { Link } from '@inertiajs/react';
import Badge from '../Badge';
import Button from '../Button';
import Drawer, { DrawerByline, DrawerDate } from '../Drawer';
import { api } from '../../lib/api';
import { formatCurrency } from '../../lib/format';

const money = (cents) => (cents ? formatCurrency(cents / 100) : '');

// One posted entry: its lines, where it came from, and what reversed it.
// An entry made by hand can be reversed here (dated like the original,
// or another day when that one's locked); one the app posted follows its
// expense or payment, so it links there instead.
export default function EntryDrawer({ entry, onReversed, onClose }) {
    const [reverseOn, setReverseOn] = useState(entry.entry_date);
    const [reversing, setReversing] = useState(false);
    const [error, setError] = useState('');
    const canReverse = entry.manual && !entry.reverses && !entry.reversed_by;
    const debits = entry.lines.reduce((sum, line) => sum + line.debit_cents, 0);
    const credits = entry.lines.reduce((sum, line) => sum + line.credit_cents, 0);

    async function reverse() {
        if (!confirm(`Reverse entry #${entry.entry_number}? A mirror-image entry cancels it out; the original stays on the books.`)) return;
        setReversing(true);
        setError('');
        try {
            await api.post(`/api/journal-entries/${entry.id}/reverse`, { entry_date: reverseOn });
            onReversed();
            onClose();
        } catch (err) {
            setError(err.message || 'Could not reverse this entry.');
        } finally {
            setReversing(false);
        }
    }

    return (
        <Drawer onClose={onClose} size="wide">
            <DrawerByline>
                <DrawerDate label="Dated" date={entry.entry_date} />
                {entry.created_by && <span>Posted by {entry.created_by}</span>}
                {entry.reverses && <Badge tone="neutral" label={`Reverses #${entry.reverses.entry_number}`} />}
                {entry.reversed_by && <Badge tone="warning" label={`Reversed by #${entry.reversed_by.entry_number}`} />}
            </DrawerByline>
            <h2 className="drawer__title">Entry #{entry.entry_number}: {entry.memo}</h2>

            <p className="form-hint drawer__section">
                {entry.source
                    ? <>Posted for {entry.source.kind.toLowerCase()} {entry.source.url ? <Link href={entry.source.url}>{entry.source.label}</Link> : entry.source.label}. To change it, change that.</>
                    : 'Made by hand.'}
            </p>

            <div className="card card--flush drawer__section">
                <table className="table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Client</th>
                            <th className="table__cell--end">Debit</th>
                            <th className="table__cell--end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entry.lines.map((line) => (
                            <tr key={line.id}>
                                <td>
                                    {line.account.name}
                                    {line.description && <div className="table__meta">{line.description}</div>}
                                </td>
                                <td className="table__cell--muted">{line.company?.name}</td>
                                <td className="table__cell--end table__cell--numeric">{money(line.debit_cents)}</td>
                                <td className="table__cell--end table__cell--numeric">{money(line.credit_cents)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td className="table__cell--strong">Total</td>
                            <td />
                            <td className="table__cell--end table__cell--numeric table__cell--strong">{money(debits)}</td>
                            <td className="table__cell--end table__cell--numeric table__cell--strong">{money(credits)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {canReverse && (
                <div className="form-panel">
                    <div className="section-label section-label--ruled">Reverse</div>
                    <p className="form-hint">Cancels this entry with a mirror image. Use the original date unless its period is locked.</p>
                    <div className="inline-form">
                        <input type="date" aria-label="Reverse on" value={reverseOn} onChange={(e) => setReverseOn(e.target.value)} className="input input--xs" />
                        <Button variant="danger" onClick={reverse} disabled={reversing}>Reverse entry</Button>
                    </div>
                    {error && <div className="form-message form-message--error">{error}</div>}
                </div>
            )}
        </Drawer>
    );
}

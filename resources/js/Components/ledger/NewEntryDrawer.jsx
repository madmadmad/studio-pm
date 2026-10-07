import { useState } from 'react';
import { X } from '@phosphor-icons/react';
import CurrencyInput from '../CurrencyInput';
import AccountSelect from './AccountSelect';
import EntryFormDrawer, { Field, cents } from './EntryFormDrawer';
import { formatCurrency, todayInAppTimezone } from '../../lib/format';

function blankLine() {
    return { account_id: null, company_id: '', debit: '', credit: '' };
}

// A general journal entry: any balanced set of lines -- opening balances,
// a CPA's year-end adjustment, a correction. Posts once debits and
// credits agree.
export default function NewEntryDrawer({ accounts, companies, onPosted, onClose }) {
    const [date, setDate] = useState(todayInAppTimezone());
    const [memo, setMemo] = useState('');
    const [lines, setLines] = useState([blankLine(), blankLine()]);

    const debits = lines.reduce((sum, line) => sum + cents(line.debit), 0);
    const credits = lines.reduce((sum, line) => sum + cents(line.credit), 0);
    const complete = lines.filter((line) => line.account_id && (cents(line.debit) > 0 || cents(line.credit) > 0));
    const balanced = debits > 0 && debits === credits;

    // A line is a debit or a credit: entering one clears the other.
    function update(index, changes) {
        setLines(lines.map((line, i) => {
            if (i !== index) return line;
            const next = { ...line, ...changes };
            if ('debit' in changes && changes.debit !== '') next.credit = '';
            if ('credit' in changes && changes.credit !== '') next.debit = '';
            return next;
        }));
    }

    return (
        <EntryFormDrawer
            title="New journal entry"
            hint="For anything the other forms don't cover: opening balances, the CPA's year-end adjustments, a correction."
            endpoint="/api/journal-entries"
            size="wide"
            canPost={balanced && complete.length >= 2 && memo.trim() !== ''}
            payload={() => ({
                entry_date: date,
                memo,
                lines: complete.map((line) => ({
                    account_id: line.account_id,
                    company_id: line.company_id || null,
                    debit: line.debit || null,
                    credit: line.credit || null,
                })),
            })}
            onPosted={onPosted}
            onClose={onClose}
        >
            <div className="form-grid drawer__section">
                <Field label="Date">
                    <input required type="date" value={date} onChange={(e) => setDate(e.target.value)} className="input input--xs" />
                </Field>
                <Field label="Memo">
                    <input required placeholder="e.g. Opening balances" value={memo} onChange={(e) => setMemo(e.target.value)} className="input input--xs" />
                </Field>
            </div>

            <div className="journal-lines drawer__section">
                <div className="journal-lines__row journal-lines__head" aria-hidden="true">
                    <span>Account</span>
                    <span>Client</span>
                    <span className="journal-lines__amount">Debit</span>
                    <span className="journal-lines__amount">Credit</span>
                    <span />
                </div>
                {lines.map((line, i) => (
                    <div key={i} className="journal-lines__row">
                        <AccountSelect
                            accounts={accounts}
                            value={line.account_id}
                            blankLabel="Account…"
                            label={`Line ${i + 1} account`}
                            onChange={(account_id) => update(i, { account_id })}
                            className="input input--xs journal-lines__account"
                        />
                        <select aria-label={`Line ${i + 1} client`} value={line.company_id} onChange={(e) => update(i, { company_id: e.target.value })} className="input input--xs journal-lines__client">
                            <option value="">No client</option>
                            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        <CurrencyInput aria-label={`Line ${i + 1} debit`} placeholder="Debit" value={line.debit} onChange={(debit) => update(i, { debit })} className="input input--xs journal-lines__amount" />
                        <CurrencyInput aria-label={`Line ${i + 1} credit`} placeholder="Credit" value={line.credit} onChange={(credit) => update(i, { credit })} className="input input--xs journal-lines__amount" />
                        <button
                            type="button"
                            onClick={() => setLines(lines.filter((_, j) => j !== i))}
                            disabled={lines.length <= 2}
                            title="Remove line"
                            aria-label={`Remove line ${i + 1}`}
                            className="icon-btn icon-btn--danger"
                        >
                            <X />
                        </button>
                    </div>
                ))}
                <div className="journal-lines__row journal-lines__totals">
                    <button type="button" onClick={() => setLines([...lines, blankLine()])} className="text-action">+ Add line</button>
                    <span className="journal-lines__client" />
                    <span className="journal-lines__amount">{formatCurrency(debits / 100)}</span>
                    <span className="journal-lines__amount">{formatCurrency(credits / 100)}</span>
                    <span />
                </div>
                {debits !== credits && (
                    <div className="journal-lines__difference">Out of balance by {formatCurrency(Math.abs(debits - credits) / 100)}</div>
                )}
            </div>
        </EntryFormDrawer>
    );
}

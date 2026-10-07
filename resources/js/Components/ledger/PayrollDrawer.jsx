import { useState } from 'react';
import CurrencyInput from '../CurrencyInput';
import EntryFormDrawer, { Field, cents } from './EntryFormDrawer';
import { formatCurrency, todayInAppTimezone } from '../../lib/format';

const PARTS = [
    ['officer_compensation', 'Officer compensation'],
    ['wages', 'Wages'],
    ['payroll_taxes', 'Payroll taxes'],
    ['retirement_expense', 'Retirement match'],
];

// One pay period from the payroll provider's report: each cost to its
// account, the total out of checking (the provider draws it all there).
export default function PayrollDrawer({ onPosted, onClose }) {
    const [form, setForm] = useState({ entry_date: todayInAppTimezone(), memo: '', ...Object.fromEntries(PARTS.map(([key]) => [key, ''])) });
    const total = PARTS.reduce((sum, [key]) => sum + cents(form[key]), 0);

    return (
        <EntryFormDrawer
            title="Payroll"
            hint="One pay period, from the payroll provider's report. The total comes out of checking."
            endpoint="/api/journal-entries/payroll"
            canPost={total > 0}
            payload={() => form}
            onPosted={onPosted}
            onClose={onClose}
        >
            <div className="form-grid drawer__section">
                <Field label="Pay date">
                    <input required type="date" value={form.entry_date} onChange={(e) => setForm({ ...form, entry_date: e.target.value })} className="input input--xs" />
                </Field>
                <Field label="Memo">
                    <input placeholder="e.g. Payroll, Mar 1–15" value={form.memo} onChange={(e) => setForm({ ...form, memo: e.target.value })} className="input input--xs" />
                </Field>
                {PARTS.map(([key, label]) => (
                    <Field key={key} label={label}>
                        <CurrencyInput value={form[key]} onChange={(value) => setForm({ ...form, [key]: value })} className="input input--xs" />
                    </Field>
                ))}
            </div>
            <p className="form-hint drawer__section">Out of checking: <strong className="u-tabular-nums">{formatCurrency(total / 100)}</strong></p>
        </EntryFormDrawer>
    );
}

import { useState } from 'react';
import CurrencyInput from '../CurrencyInput';
import EntryFormDrawer, { Field, cents } from './EntryFormDrawer';
import { formatCurrency, todayInAppTimezone } from '../../lib/format';

const COSTS = [
    ['officer_compensation', 'Officer compensation'],
    ['wages', 'Wages'],
    ['payroll_taxes', 'Employer payroll taxes'],
    ['retirement_expense', 'Retirement match'],
];

// What the bank shows leaving checking for the pay run.
const DEBITS = [
    ['payroll_debit', 'Data Service debit'],
    ['retirement_debit', 'American Funds debit'],
];

// The import's limit: more left over than this isn't a health deduction.
const HEALTH_LIMIT_CENTS = 150000;

// One pay run from the payroll report: its costs, and the Data Service
// and American Funds debits from checking. What the costs come to beyond
// the debits is the employees' health insurance deduction (it stayed in
// checking), credited back to Health & Life Insurance -- worked out here
// as they type, and flagged if it can't be right.
export default function PayrollDrawer({ onPosted, onClose }) {
    const [form, setForm] = useState({
        entry_date: todayInAppTimezone(),
        memo: '',
        ...Object.fromEntries([...COSTS, ...DEBITS].map(([key]) => [key, ''])),
    });
    const costs = COSTS.reduce((sum, [key]) => sum + cents(form[key]), 0);
    const paid = DEBITS.reduce((sum, [key]) => sum + cents(form[key]), 0);
    const deduction = costs - paid;
    const complete = costs > 0 && cents(form.payroll_debit) > 0;

    const field = ([key, label]) => (
        <Field key={key} label={label}>
            <CurrencyInput value={form[key]} onChange={(value) => setForm({ ...form, [key]: value })} className="input input--xs" />
        </Field>
    );

    return (
        <EntryFormDrawer
            title="Payroll"
            hint="One pay run, from the payroll report and the bank statement. Costs go to their accounts; the two debits come out of checking."
            endpoint="/api/journal-entries/payroll"
            canPost={complete && deduction >= 0}
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
            </div>

            <div className="section-label">Costs (payroll report)</div>
            <div className="form-grid drawer__section">{COSTS.map(field)}</div>

            <div className="section-label">Out of checking (bank statement)</div>
            <div className="form-grid drawer__section">{DEBITS.map(field)}</div>

            <div className="detail-list drawer__section">
                {[
                    ['Costs', costs],
                    ['Out of checking', paid],
                    ['Employees’ health deduction', Math.max(deduction, 0)],
                ].map(([label, amount]) => (
                    <div key={label} className="detail-list__item detail-list__item--split">
                        <span className="detail-list__title">{label}</span>
                        <span className="u-tabular-nums">{formatCurrency(amount / 100)}</span>
                    </div>
                ))}
            </div>
            {complete && deduction < 0 && (
                <div className="form-message form-message--error drawer__section">
                    More came out of checking than the costs add up to. Check the amounts against the payroll report.
                </div>
            )}
            {complete && deduction > HEALTH_LIMIT_CENTS && (
                <div className="form-message drawer__section">
                    That&rsquo;s a large health deduction. If it&rsquo;s not right, a cost or a debit is probably missing.
                </div>
            )}
            {complete && deduction >= 0 && deduction <= HEALTH_LIMIT_CENTS && (
                <p className="form-hint drawer__section">
                    {deduction > 0
                        ? 'The health deduction goes back to Health & Life Insurance, as the employees’ share of the premiums.'
                        : 'No health deduction this pay run.'}
                </p>
            )}
        </EntryFormDrawer>
    );
}

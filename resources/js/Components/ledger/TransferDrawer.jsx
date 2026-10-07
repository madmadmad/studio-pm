import { useState } from 'react';
import CurrencyInput from '../CurrencyInput';
import AccountSelect, { BALANCE_SHEET } from './AccountSelect';
import EntryFormDrawer, { Field, cents } from './EntryFormDrawer';
import { todayInAppTimezone } from '../../lib/format';

// The everyday moves between the studio's own accounts, by the handles
// of the accounts they're between.
const PRESETS = [
    { label: 'Card payment', from: 'checking', to: 'capital_one_card' },
    { label: 'Owner contribution', from: 'shareholder_capital', to: 'checking' },
    { label: 'Distribution', from: 'checking', to: 'shareholder_distributions' },
    { label: 'Sales tax payment', from: 'checking', to: 'sales_tax_payable' },
];

// Money moved between the studio's own accounts -- the monthly Capital
// One payment from checking above all. A transfer, never an expense.
export default function TransferDrawer({ accounts, onPosted, onClose }) {
    const idFor = (key) => accounts.find((a) => a.system_key === key)?.id ?? null;
    const [form, setForm] = useState({ entry_date: todayInAppTimezone(), from_account_id: idFor('checking'), to_account_id: idFor('capital_one_card'), amount: '', memo: '' });
    const balanceSheet = (a) => BALANCE_SHEET.includes(a.type);

    return (
        <EntryFormDrawer
            title="Transfer"
            hint="Money moved between our own accounts: paying the card from checking, putting money in or taking it out, sending the state its sales tax. Never an expense."
            endpoint="/api/journal-entries/transfer"
            canPost={form.from_account_id && form.to_account_id && form.from_account_id !== form.to_account_id && cents(form.amount) > 0}
            payload={() => form}
            onPosted={onPosted}
            onClose={onClose}
        >
            <div className="filter-bar__pills drawer__section">
                {PRESETS.map((preset) => {
                    const active = form.from_account_id === idFor(preset.from) && form.to_account_id === idFor(preset.to);
                    return (
                        <button
                            key={preset.label}
                            type="button"
                            onClick={() => setForm({ ...form, from_account_id: idFor(preset.from), to_account_id: idFor(preset.to) })}
                            className={`filter-bar__pill${active ? ' filter-bar__pill--active' : ''}`}
                        >
                            {preset.label}
                        </button>
                    );
                })}
            </div>
            <div className="form-grid drawer__section">
                <Field label="From">
                    <AccountSelect accounts={accounts} filter={balanceSheet} value={form.from_account_id} label="From" onChange={(from_account_id) => setForm({ ...form, from_account_id })} />
                </Field>
                <Field label="To">
                    <AccountSelect accounts={accounts} filter={balanceSheet} value={form.to_account_id} label="To" onChange={(to_account_id) => setForm({ ...form, to_account_id })} />
                </Field>
                <Field label="Amount">
                    <CurrencyInput required value={form.amount} onChange={(amount) => setForm({ ...form, amount })} className="input input--xs" />
                </Field>
                <Field label="Date">
                    <input required type="date" value={form.entry_date} onChange={(e) => setForm({ ...form, entry_date: e.target.value })} className="input input--xs" />
                </Field>
                <Field label="Memo" className="form-grid__full">
                    <input placeholder="Optional, e.g. March statement" value={form.memo} onChange={(e) => setForm({ ...form, memo: e.target.value })} className="input input--xs" />
                </Field>
            </div>
        </EntryFormDrawer>
    );
}

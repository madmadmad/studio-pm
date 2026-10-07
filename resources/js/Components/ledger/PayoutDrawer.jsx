import { useState } from 'react';
import CurrencyInput from '../CurrencyInput';
import EntryFormDrawer, { Field, cents } from './EntryFormDrawer';
import { formatCurrency, todayInAppTimezone } from '../../lib/format';

// A Stripe payout landing in checking: what was deposited moves out of
// Stripe Clearing. Stripe's fees are normally booked with each payment;
// any that weren't (the drawer shows what clearing holds) go in Fees.
export default function PayoutDrawer({ clearingCents, onPosted, onClose }) {
    const [form, setForm] = useState({ entry_date: todayInAppTimezone(), deposited: '', fees: '', memo: '' });

    return (
        <EntryFormDrawer
            title="Stripe payout"
            hint={`Stripe Clearing holds ${formatCurrency(clearingCents / 100)} right now. Enter the deposit as it shows on the bank statement.`}
            endpoint="/api/journal-entries/payout"
            canPost={cents(form.deposited) > 0}
            payload={() => form}
            onPosted={onPosted}
            onClose={onClose}
        >
            <div className="form-grid drawer__section">
                <Field label="Deposited to checking">
                    <CurrencyInput required value={form.deposited} onChange={(deposited) => setForm({ ...form, deposited })} className="input input--xs" />
                </Field>
                <Field label="Date">
                    <input required type="date" value={form.entry_date} onChange={(e) => setForm({ ...form, entry_date: e.target.value })} className="input input--xs" />
                </Field>
                <Field label="Fees not booked yet" className="form-grid__full">
                    <CurrencyInput placeholder="Usually none" value={form.fees} onChange={(fees) => setForm({ ...form, fees })} className="input input--xs" />
                    <div className="form-hint form-hint--attached">Only fees Stripe took that weren't recorded with their payment. They're added to what leaves clearing.</div>
                </Field>
                <Field label="Memo" className="form-grid__full">
                    <input placeholder="Stripe payout" value={form.memo} onChange={(e) => setForm({ ...form, memo: e.target.value })} className="input input--xs" />
                </Field>
            </div>
        </EntryFormDrawer>
    );
}

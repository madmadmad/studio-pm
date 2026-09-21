import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import MetricCard from '../../Components/MetricCard';
import EmptyState from '../../Components/EmptyState';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

function emptyForm() {
    return { amount: '', category: '', occurred_on: new Date().toISOString().slice(0, 10), description: '' };
}

export default function BookkeepingIndex({ transactions, summary }) {
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(emptyForm());
    const [saving, setSaving] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        try {
            await api.post('/api/transactions', form);
            setForm(emptyForm());
            setShowForm(false);
            router.reload({ only: ['transactions', 'summary'] });
        } finally {
            setSaving(false);
        }
    }

    async function remove(transaction) {
        await api.delete(`/api/transactions/${transaction.id}`);
        router.reload({ only: ['transactions', 'summary'] });
    }

    return (
        <AppLayout>
            <Head title="Bookkeeping" />
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">Bookkeeping</h1>
                <Button onClick={() => setShowForm(true)}>Add income</Button>
            </div>
            <p className="text-sm text-shadow-grey mb-6">
                Income, month by month &mdash; not double-entry accounting. Expenses are tracked on the{' '}
                <Link href="/expenses" className="underline">Expenses</Link> page.
            </p>

            <div className="grid grid-cols-3 gap-4 mb-8">
                <MetricCard label={`Income (${summary.month})`} value={formatCurrency(summary.income)} />
                <MetricCard label="Expenses" value={formatCurrency(summary.expenses)} />
                <MetricCard label="Net" value={formatCurrency(summary.net)} />
            </div>

            {showForm && (
                <form onSubmit={submit} className="card card-padded mb-6 grid grid-cols-2 gap-3">
                    <input required type="number" min="0.01" step="0.01" placeholder="Amount" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="field tabular-nums" />
                    <input placeholder="Category" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className="field" />
                    <input required type="date" value={form.occurred_on} onChange={(e) => setForm({ ...form, occurred_on: e.target.value })} className="field" />
                    <input placeholder="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="field" />
                    <div className="flex gap-2 col-span-2 justify-end">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                        <Button type="submit" variant="confirm" disabled={saving}>Save</Button>
                    </div>
                </form>
            )}

            <div className="card overflow-hidden">
                {transactions.length === 0 ? (
                    <EmptyState text="No income logged yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th className="text-right">Amount</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {transactions.map((t) => (
                                <tr key={t.id}>
                                    <td>{formatDate(t.occurred_on)}</td>
                                    <td className="text-shadow-grey">{t.category ?? '—'}</td>
                                    <td className="text-shadow-grey">{t.description}</td>
                                    <td className="text-right tabular-nums text-fern">+{formatCurrency(t.amount)}</td>
                                    <td className="text-right">
                                        <button onClick={() => remove(t)} className="text-xs text-shadow-grey hover:text-fuchsia">Remove</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}

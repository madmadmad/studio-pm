import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import EmptyState from '../../Components/EmptyState';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

function emptyForm() {
    return { type: 'expense', amount: '', category: '', occurred_on: new Date().toISOString().slice(0, 10), description: '' };
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
                <button onClick={() => setShowForm(true)} className="bg-gunmetal text-white text-sm font-medium px-3 py-1.5 rounded">
                    Add transaction
                </button>
            </div>
            <p className="text-sm text-shadow-grey mb-6">Income vs. expense, month by month &mdash; not double-entry accounting.</p>

            <div className="grid grid-cols-3 gap-4 mb-8">
                <MetricCard label={`Income (${summary.month})`} value={formatCurrency(summary.income)} />
                <MetricCard label="Expenses" value={formatCurrency(summary.expenses)} />
                <MetricCard label="Net" value={formatCurrency(summary.net)} />
            </div>

            {showForm && (
                <form onSubmit={submit} className="bg-white rounded-lg border border-border p-4 mb-6 grid grid-cols-2 gap-3">
                    <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className="border border-border rounded px-3 py-2 text-sm">
                        <option value="income">Income</option>
                        <option value="expense">Expense</option>
                    </select>
                    <input required type="number" min="0.01" step="0.01" placeholder="Amount" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="border border-border rounded px-3 py-2 text-sm tabular-nums" />
                    <input placeholder="Category" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                    <input required type="date" value={form.occurred_on} onChange={(e) => setForm({ ...form, occurred_on: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                    <input placeholder="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="border border-border rounded px-3 py-2 text-sm col-span-2" />
                    <div className="flex gap-2 col-span-2 justify-end">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 rounded text-shadow-grey">Cancel</button>
                        <button type="submit" disabled={saving} className="bg-fern text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Save</button>
                    </div>
                </form>
            )}

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {transactions.length === 0 ? (
                    <EmptyState text="No transactions yet." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-shadow-grey">
                                <th className="px-4 py-2 font-medium">Date</th>
                                <th className="px-4 py-2 font-medium">Type</th>
                                <th className="px-4 py-2 font-medium">Category</th>
                                <th className="px-4 py-2 font-medium">Description</th>
                                <th className="px-4 py-2 font-medium text-right">Amount</th>
                                <th className="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {transactions.map((t) => (
                                <tr key={t.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-2">{formatDate(t.occurred_on)}</td>
                                    <td className="px-4 py-2 capitalize">{t.type}</td>
                                    <td className="px-4 py-2 text-shadow-grey">{t.category ?? '—'}</td>
                                    <td className="px-4 py-2 text-shadow-grey">{t.description}</td>
                                    <td className={`px-4 py-2 text-right tabular-nums ${t.type === 'income' ? 'text-fern' : 'text-fuchsia'}`}>
                                        {t.type === 'income' ? '+' : '-'}{formatCurrency(t.amount)}
                                    </td>
                                    <td className="px-4 py-2 text-right">
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

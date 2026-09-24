import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import MetricCard from '../../Components/MetricCard';
import EmptyState from '../../Components/EmptyState';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';

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
            <PageHeader
                title="Bookkeeping"
                actions={<Button onClick={() => setShowForm(true)}>Add income</Button>}
                subtitle={
                    <>
                        Income, month by month &mdash; not double-entry accounting. Expenses are tracked on the{' '}
                        <Link href="/expenses" className="link link--inline">Expenses</Link> page.
                    </>
                }
            />

            <div className="metric-grid">
                <MetricCard label={`Income (${summary.month})`} value={formatCurrency(summary.income)} />
                <MetricCard label="Expenses" value={formatCurrency(summary.expenses)} />
                <MetricCard label="Net" value={formatCurrency(summary.net)} />
            </div>

            {showForm && (
                <form onSubmit={submit} className="card card--padded form-grid page-section">
                    <input required type="number" min="0.01" step="0.01" placeholder="Amount" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="input u-tabular-nums" />
                    <input placeholder="Category" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className="input" />
                    <input required type="date" value={form.occurred_on} onChange={(e) => setForm({ ...form, occurred_on: e.target.value })} className="input" />
                    <input placeholder="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="input" />
                    <div className="form-actions form-grid__full">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                        <Button type="submit" variant="confirm" disabled={saving}>Save</Button>
                    </div>
                </form>
            )}

            <div className="card card--flush">
                {transactions.length === 0 ? (
                    <EmptyState text="No income logged yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th className="table__cell--end">Amount</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {transactions.map((t) => (
                                <tr key={t.id}>
                                    <td>{formatDate(t.occurred_on)}</td>
                                    <td className="table__cell--muted">{t.category ?? '—'}</td>
                                    <td className="table__cell--muted">{t.description}</td>
                                    <td className="table__cell--end table__cell--numeric table__cell--positive">+{formatCurrency(t.amount)}</td>
                                    <td className="table__cell--end">
                                        <button onClick={() => remove(t)} className="text-action text-action--danger text-action--xs">Remove</button>
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

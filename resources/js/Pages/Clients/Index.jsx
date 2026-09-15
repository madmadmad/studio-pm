import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { CompanyStatusBadge } from '../../Components/StatusBadges';
import { formatCurrency } from '../../lib/format';
import { api } from '../../lib/api';

export default function ClientsIndex({ companies }) {
    const [showForm, setShowForm] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [form, setForm] = useState({ name: '', email: '', phone: '', default_hourly_rate: '' });

    async function handleSubmit(e) {
        e.preventDefault();
        if (!form.name) return;
        setSaving(true);
        setError('');
        try {
            await api.post('/api/companies', form);
            setForm({ name: '', email: '', phone: '', default_hourly_rate: '' });
            setShowForm(false);
            router.reload({ only: ['companies'] });
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <AppLayout>
            <Head title="Clients" />
            <div className="flex items-center justify-between mb-1">
                <h1 className="text-2xl font-semibold">Clients</h1>
                <button
                    onClick={() => setShowForm(true)}
                    className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded"
                >
                    Add client
                </button>
            </div>
            <p className="text-sm text-sage mb-6">
                {companies.length} client{companies.length !== 1 ? 's' : ''} on file.
            </p>

            {showForm && (
                <form onSubmit={handleSubmit} className="bg-white rounded-lg border border-border p-4 mb-6 grid grid-cols-2 gap-3">
                    <input
                        required
                        placeholder="Client or company name"
                        value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm col-span-2"
                    />
                    <input
                        type="email"
                        placeholder="Email"
                        value={form.email}
                        onChange={(e) => setForm({ ...form, email: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm"
                    />
                    <input
                        placeholder="Phone"
                        value={form.phone}
                        onChange={(e) => setForm({ ...form, phone: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm"
                    />
                    <input
                        type="number"
                        min="0"
                        placeholder="Default hourly rate ($)"
                        value={form.default_hourly_rate}
                        onChange={(e) => setForm({ ...form, default_hourly_rate: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm col-span-2"
                    />
                    {error && <div className="text-sm text-brick col-span-2">{error}</div>}
                    <div className="flex gap-2 col-span-2 justify-end">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 rounded text-sage">
                            Cancel
                        </button>
                        <button type="submit" disabled={saving} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">
                            Save client
                        </button>
                    </div>
                </form>
            )}

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {companies.length === 0 ? (
                    <EmptyState text="No clients yet." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Client</th>
                                <th className="px-4 py-2 font-medium">Contact</th>
                                <th className="px-4 py-2 font-medium">Rate</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {companies.map((c) => (
                                <tr key={c.id} className="border-b border-border last:border-b-0 hover:bg-paper">
                                    <td className="px-4 py-3 font-medium">
                                        <Link href={`/clients/${c.id}`} className="hover:underline">
                                            {c.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-sage">
                                        {c.contacts?.[0] ? `${c.contacts[0].name} · ${c.contacts[0].email ?? ''}` : '—'}
                                    </td>
                                    <td className="px-4 py-3 font-mono">
                                        {c.default_hourly_rate ? `${formatCurrency(c.default_hourly_rate)}/hr` : '—'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <CompanyStatusBadge company={c} />
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

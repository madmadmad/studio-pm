import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import { CompanyStatusBadge } from '../../Components/StatusBadges';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';

export default function ClientsIndex({ companies }) {
    const [showForm, setShowForm] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [form, setForm] = useState({ name: '', phone: '' });

    async function handleSubmit(e) {
        e.preventDefault();
        if (!form.name) return;
        setSaving(true);
        setError('');
        try {
            await api.post('/api/companies', form);
            setForm({ name: '', phone: '' });
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
            <PageHeader
                title="Clients"
                actions={<Button onClick={() => setShowForm(true)}>Add client</Button>}
                subtitle={
                    <>
                        {companies.length} client{companies.length !== 1 ? 's' : ''} on file.
                    </>
                }
            />

            {showForm && (
                <form onSubmit={handleSubmit} className="card card--padded mb-6 grid grid-cols-2 gap-3">
                    <input
                        required
                        placeholder="Client or company name"
                        value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                        className="input col-span-2"
                    />
                    <input
                        placeholder="Phone"
                        value={form.phone}
                        onChange={(e) => setForm({ ...form, phone: e.target.value })}
                        className="input col-span-2"
                    />
                    {error && <div className="text-sm text-watermelon col-span-2">{error}</div>}
                    <div className="flex gap-2 col-span-2 justify-end">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="confirm" disabled={saving}>
                            Save client
                        </Button>
                    </div>
                </form>
            )}

            <div className="card overflow-hidden">
                {companies.length === 0 ? (
                    <EmptyState text="No clients yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {companies.map((c) => (
                                <tr key={c.id} className="hover:bg-porcelain">
                                    <td className="font-medium">
                                        <Link href={`/clients/${c.id}`} className="hover:underline">
                                            {c.name}
                                        </Link>
                                    </td>
                                    <td className="text-shadow-grey">
                                        {c.contacts?.[0] ? `${c.contacts[0].name} · ${c.contacts[0].email ?? ''}` : '—'}
                                    </td>
                                    <td>
                                        <CompanyStatusBadge company={c} />
                                    </td>
                                    <td className="text-right">
                                        <Link href={`/clients/${c.id}`} className="link-btn">
                                            Edit
                                        </Link>
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

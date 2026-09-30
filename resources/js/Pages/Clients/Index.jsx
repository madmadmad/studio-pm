import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CaretRight } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import { CompanyStatusBadge } from '../../Components/StatusBadges';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import { visitRow } from '../../lib/rowLink';

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
                <form onSubmit={handleSubmit} className="card card--padded form-grid page-section">
                    <input
                        required
                        placeholder="Client or company name"
                        value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                        className="input form-grid__full"
                    />
                    <input
                        placeholder="Phone"
                        value={form.phone}
                        onChange={(e) => setForm({ ...form, phone: e.target.value })}
                        className="input form-grid__full"
                    />
                    {error && <div className="form-message form-message--error form-grid__full">{error}</div>}
                    <div className="form-actions form-grid__full">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="confirm" disabled={saving}>
                            Save client
                        </Button>
                    </div>
                </form>
            )}

            <div className="card card--flush">
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
                                <tr key={c.id} onClick={(e) => visitRow(e, `/clients/${c.id}`)} className="table__row--link">
                                    <td className="table__cell--strong">{c.name}</td>
                                    <td className="table__cell--muted">
                                        {c.contacts?.[0] ? `${c.contacts[0].name} · ${c.contacts[0].email ?? ''}` : '—'}
                                    </td>
                                    <td>
                                        <CompanyStatusBadge company={c} />
                                    </td>
                                    <td className="table__cell--end">
                                        <div className="table__actions">
                                            {/* The keyboard way in; the row's own click does the same. */}
                                            <Link href={`/clients/${c.id}`} title="Open client" aria-label="Open client" className="row-action">
                                                <CaretRight size={14} weight="bold" />
                                            </Link>
                                        </div>
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

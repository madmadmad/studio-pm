import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CaretRight, Eye } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import { CompanyStatusBadge } from '../../Components/StatusBadges';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import { visitRow } from '../../lib/rowLink';
import { useListMotion } from '../../lib/listMotion';
import TabToolbar from '../../Components/TabToolbar';

const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

export default function ClientsIndex({ companies }) {
    const [search, setSearch] = useState('');
    const rowsRef = useListMotion();
    const [filter, setFilter] = useState('all');
    const [showForm, setShowForm] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [form, setForm] = useState({ name: '', phone: '' });

    // Search matches a client's name or any of its contacts' names or
    // emails; the pills filter by status. Same bar as the Projects list.
    const query = search.trim().toLowerCase();
    const visibleCompanies = companies.filter((c) => {
        if (filter !== 'all' && (c.status === 'active' ? 'active' : 'inactive') !== filter) return false;
        if (!query) return true;
        return c.name.toLowerCase().includes(query)
            || (c.contacts || []).some((contact) => contact.name?.toLowerCase().includes(query) || contact.email?.toLowerCase().includes(query));
    });

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

            {companies.length > 0 && (
                <div className="filter-bar">
                    <input
                        type="search"
                        placeholder="Search clients or contacts…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        aria-label="Search clients"
                        className="input filter-bar__search"
                    />
                    <div className="filter-bar__pills">
                        {STATUS_FILTERS.map((s) => (
                            <button
                                key={s.value}
                                onClick={() => setFilter(s.value)}
                                className={`filter-bar__pill${filter === s.value ? ' filter-bar__pill--active' : ''}`}
                            >
                                {s.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* The add action, directly above the list it adds to. */}
            <TabToolbar addLabel="Add client" onAdd={() => setShowForm(true)} />
            <div className="card card--flush">
                {companies.length === 0 ? (
                    <EmptyState text="No clients yet." />
                ) : visibleCompanies.length === 0 ? (
                    <EmptyState text={query ? `No clients match "${search.trim()}".` : 'No clients match this filter.'} />
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
                        <tbody ref={rowsRef}>
                            {visibleCompanies.map((c) => (
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
                                            {/* A read-only look at the client's portal, in a new tab
                                                (PortalPreviewController) -- once someone there has access. */}
                                            {c.contacts?.some((contact) => contact.has_portal_access) ? (
                                                <a
                                                    href={`/clients/${c.id}/portal-preview`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title="Preview client portal"
                                                    aria-label="Preview client portal"
                                                    className="icon-btn icon-btn--secondary"
                                                >
                                                    <Eye />
                                                </a>
                                            ) : (
                                                <span title="No one here has portal access yet" className="icon-btn icon-btn--secondary icon-btn--unavailable">
                                                    <Eye />
                                                </span>
                                            )}
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

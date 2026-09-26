import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import ProjectsTable from '../../Components/ProjectsTable';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';

const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'leads', label: 'Leads' },
    { value: 'estimated', label: 'Estimated' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'completed', label: 'Completed' },
];

function emptyForm() {
    return { company_id: '', contact_id: '', name: '', description: '' };
}

export default function ProjectsIndex({ projects, companies, archivedView = false }) {
    const [filter, setFilter] = useState('all');
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(emptyForm());
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const visibleProjects = filter === 'all' ? projects : projects.filter((p) => p.status === filter);
    const contactsForCompany = companies.find((c) => String(c.id) === String(form.company_id))?.contacts || [];

    function handleCompanyChange(value) {
        const company = companies.find((c) => String(c.id) === String(value));
        const primaryContact = company?.contacts?.find((c) => c.is_primary);
        setForm({ ...form, company_id: value, contact_id: primaryContact ? String(primaryContact.id) : '' });
    }

    async function submit(e) {
        e.preventDefault();
        if (!form.company_id || !form.name) {
            setError('Client and project name are both required.');
            return;
        }
        setSaving(true);
        setError('');
        try {
            await api.post(`/api/companies/${form.company_id}/projects`, {
                name: form.name,
                description: form.description,
                contact_id: form.contact_id || null,
            });
            setForm(emptyForm());
            setShowForm(false);
            router.reload({ only: ['projects'] });
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <AppLayout>
            <Head title={archivedView ? 'Archived Projects' : 'Projects'} />
            <PageHeader
                title={archivedView ? 'Archived Projects' : 'Projects'}
                actions={archivedView ? (
                    <Link href="/projects" className="link link--muted page-header__link">
                        All Projects
                    </Link>
                ) : (
                    <Button onClick={() => setShowForm(true)}>New project</Button>
                )}
                subtitle={
                    <>
                        {archivedView
                            ? `${projects.length} archived project${projects.length !== 1 ? 's' : ''}.`
                            : `${projects.length} project${projects.length !== 1 ? 's' : ''} across all clients.`}
                    </>
                }
            />

            {archivedView ? null : (
                <div className="filter-bar">
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
                    <Link href="/projects/archived" className="link link--muted filter-bar__link">
                        Archived
                    </Link>
                </div>
            )}

            {showForm && (
                <form onSubmit={submit} className="card card--padded form-grid page-section">
                    <select
                        required
                        value={form.company_id}
                        onChange={(e) => handleCompanyChange(e.target.value)}
                        className="input"
                    >
                        <option value="">Select client</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <select
                        value={form.contact_id}
                        disabled={!form.company_id}
                        onChange={(e) => setForm({ ...form, contact_id: e.target.value })}
                        className="input"
                    >
                        <option value="">
                            {form.company_id ? 'No contact' : 'Select a client first'}
                        </option>
                        {contactsForCompany.map((contact) => (
                            <option key={contact.id} value={contact.id}>
                                {contact.name}{contact.email ? ` (${contact.email})` : ''}
                            </option>
                        ))}
                    </select>
                    <input
                        required
                        placeholder="Project name"
                        value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                        className="input form-grid__full"
                    />
                    <input
                        placeholder="Description"
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        className="input form-grid__full"
                    />
                    {error && <div className="form-message form-message--error form-grid__full">{error}</div>}
                    <div className="form-actions form-grid__full">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                        <Button type="submit" variant="confirm" disabled={saving}>Save project</Button>
                    </div>
                </form>
            )}

            <div className="card card--flush">
                {visibleProjects.length === 0 ? (
                    <EmptyState text="No projects match this filter." />
                ) : (
                    <ProjectsTable projects={visibleProjects} onChange={() => router.reload({ only: ['projects'] })} />
                )}
            </div>
        </AppLayout>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import { ProjectStatusBadge } from '../../Components/StatusBadges';
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

const STATUS_OPTIONS = [
    { value: 'leads', label: 'Leads' },
    { value: 'estimated', label: 'Estimated' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'completed', label: 'Completed' },
    { value: 'archived', label: 'Archived' },
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
    const [pendingStatus, setPendingStatus] = useState({});

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

    async function changeStatus(project, status) {
        setPendingStatus({ ...pendingStatus, [project.id]: true });
        try {
            await api.patch(`/api/projects/${project.id}`, { status });
            router.reload({ only: ['projects'] });
        } finally {
            setPendingStatus({ ...pendingStatus, [project.id]: false });
        }
    }

    function taskProgress(project) {
        const total = project.tasks.length;
        if (total === 0) return '—';
        const done = project.tasks.filter((t) => t.status === 'done').length;
        return `${done}/${total} done`;
    }

    return (
        <AppLayout>
            <Head title={archivedView ? 'Archived Projects' : 'Projects'} />
            <PageHeader
                title={archivedView ? 'Archived Projects' : 'Projects'}
                actions={archivedView ? (
                    <Link href="/projects" className="text-sm font-medium text-shadow-grey hover:underline">
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
                <div className="flex items-center justify-between mb-6">
                    <div className="flex gap-1">
                        {STATUS_FILTERS.map((s) => (
                            <button
                                key={s.value}
                                onClick={() => setFilter(s.value)}
                                className={`text-sm px-3 py-1.5 rounded ${
                                    filter === s.value ? 'bg-gunmetal text-white' : 'text-shadow-grey border border-border'
                                }`}
                            >
                                {s.label}
                            </button>
                        ))}
                    </div>
                    <Link href="/projects/archived" className="text-sm text-shadow-grey hover:underline">
                        Archived
                    </Link>
                </div>
            )}

            {showForm && (
                <form onSubmit={submit} className="card card-padded mb-6 grid grid-cols-2 gap-3">
                    <select
                        required
                        value={form.company_id}
                        onChange={(e) => handleCompanyChange(e.target.value)}
                        className="field"
                    >
                        <option value="">Select client</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <select
                        value={form.contact_id}
                        disabled={!form.company_id}
                        onChange={(e) => setForm({ ...form, contact_id: e.target.value })}
                        className="field"
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
                        className="field col-span-2"
                    />
                    <input
                        placeholder="Description"
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        className="field col-span-2"
                    />
                    {error && <div className="text-sm text-watermelon col-span-2">{error}</div>}
                    <div className="flex gap-2 col-span-2 justify-end">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                        <Button type="submit" variant="confirm" disabled={saving}>Save project</Button>
                    </div>
                </form>
            )}

            <div className="card overflow-hidden">
                {visibleProjects.length === 0 ? (
                    <EmptyState text="No projects match this filter." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Client</th>
                                <th>Tasks</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {visibleProjects.map((project) => (
                                <tr key={project.id}>
                                    <td className="font-medium">
                                        <Link href={`/projects/${project.id}`} className="hover:underline">
                                            {project.name}
                                        </Link>
                                    </td>
                                    <td>
                                        <Link href={`/clients/${project.company.id}`} className="text-shadow-grey hover:underline">
                                            {project.company.name}
                                        </Link>
                                    </td>
                                    <td className="text-shadow-grey">{taskProgress(project)}</td>
                                    <td>
                                        <div className="flex items-center gap-2">
                                            <ProjectStatusBadge project={project} />
                                            <select
                                                value={project.status}
                                                disabled={pendingStatus[project.id]}
                                                onChange={(e) => changeStatus(project, e.target.value)}
                                                className="text-xs border border-border rounded px-1 py-0.5 text-shadow-grey"
                                            >
                                                {STATUS_OPTIONS.map((s) => (
                                                    <option key={s.value} value={s.value}>{s.label}</option>
                                                ))}
                                            </select>
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

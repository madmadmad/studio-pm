import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { ProjectStatusBadge } from '../../Components/StatusBadges';
import { api } from '../../lib/api';

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
    return { company_id: '', name: '', description: '' };
}

export default function ProjectsIndex({ projects, companies, archivedView = false }) {
    const [filter, setFilter] = useState('all');
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(emptyForm());
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [pendingStatus, setPendingStatus] = useState({});

    const visibleProjects = filter === 'all' ? projects : projects.filter((p) => p.status === filter);

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
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">{archivedView ? 'Archived Projects' : 'Projects'}</h1>
                {archivedView ? (
                    <Link href="/projects" className="text-sm font-medium text-sage hover:underline">
                        All Projects
                    </Link>
                ) : (
                    <button onClick={() => setShowForm(true)} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">
                        New project
                    </button>
                )}
            </div>
            <p className="text-sm text-sage mb-4">
                {archivedView
                    ? `${projects.length} archived project${projects.length !== 1 ? 's' : ''}.`
                    : `${projects.length} project${projects.length !== 1 ? 's' : ''} across all clients.`}
            </p>

            {archivedView ? null : (
                <div className="flex items-center justify-between mb-6">
                    <div className="flex gap-1">
                        {STATUS_FILTERS.map((s) => (
                            <button
                                key={s.value}
                                onClick={() => setFilter(s.value)}
                                className={`text-sm px-3 py-1.5 rounded ${
                                    filter === s.value ? 'bg-ink text-white' : 'text-sage border border-border'
                                }`}
                            >
                                {s.label}
                            </button>
                        ))}
                    </div>
                    <Link href="/projects/archived" className="text-sm text-sage hover:underline">
                        Archived
                    </Link>
                </div>
            )}

            {showForm && (
                <form onSubmit={submit} className="bg-white rounded-lg border border-border p-4 mb-6 grid grid-cols-2 gap-3">
                    <select
                        required
                        value={form.company_id}
                        onChange={(e) => setForm({ ...form, company_id: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm col-span-2"
                    >
                        <option value="">Select client</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <input
                        required
                        placeholder="Project name"
                        value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm col-span-2"
                    />
                    <input
                        placeholder="Description"
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        className="border border-border rounded px-3 py-2 text-sm col-span-2"
                    />
                    {error && <div className="text-sm text-brick col-span-2">{error}</div>}
                    <div className="flex gap-2 col-span-2 justify-end">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 rounded text-sage">Cancel</button>
                        <button type="submit" disabled={saving} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Save project</button>
                    </div>
                </form>
            )}

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {visibleProjects.length === 0 ? (
                    <EmptyState text="No projects match this filter." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Project</th>
                                <th className="px-4 py-2 font-medium">Client</th>
                                <th className="px-4 py-2 font-medium">Tasks</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {visibleProjects.map((project) => (
                                <tr key={project.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-3 font-medium">
                                        <Link href={`/projects/${project.id}`} className="hover:underline">
                                            {project.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link href={`/clients/${project.company.id}`} className="text-sage hover:underline">
                                            {project.company.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-sage">{taskProgress(project)}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2">
                                            <ProjectStatusBadge project={project} />
                                            <select
                                                value={project.status}
                                                disabled={pendingStatus[project.id]}
                                                onChange={(e) => changeStatus(project, e.target.value)}
                                                className="text-xs border border-border rounded px-1 py-0.5 text-sage"
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

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import ProjectsTable from '../../Components/ProjectsTable';
import ProjectBoard from '../../Components/ProjectBoard';
import ViewToggle from '../../Components/ViewToggle';
import { useRememberedTab } from '../../lib/useRememberedTab';
import { Kanban, ListBullets, Star } from '@phosphor-icons/react';
import { useFavorites } from '../../Components/StarButton';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import MetricCard from '../../Components/MetricCard';
import { formatCurrency, todayInAppTimezone } from '../../lib/format';
import TabToolbar from '../../Components/TabToolbar';

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

const VIEWS = [
    { value: 'list', label: 'List', icon: <ListBullets /> },
    { value: 'board', label: 'Board', icon: <Kanban /> },
];

// Tasks past their due date and not done, across the listed projects.
function overdueTaskCount(projects) {
    const today = todayInAppTimezone();
    return projects
        .flatMap((p) => p.tasks || [])
        .filter((t) => t.status !== 'done' && t.due_date && t.due_date.slice(0, 10) < today)
        .length;
}

// The figures across the top, as on the Invoices page (managers only --
// `metrics` comes from the server for them): each a count and an amount,
// with overdue tasks last and red only when there are any.
function ProjectMetrics({ metrics, projects }) {
    const overdue = overdueTaskCount(projects);
    return (
        <div className="metric-grid">
            <MetricCard label={`Active (${metrics.active.count})`} value={formatCurrency(metrics.active.amount)} />
            <MetricCard label={`Estimated (${metrics.estimated.count})`} value={formatCurrency(metrics.estimated.amount)} />
            <MetricCard label={`Left to invoice (${metrics.left_to_invoice.count})`} value={formatCurrency(metrics.left_to_invoice.amount)} />
            <MetricCard label="Overdue tasks" value={overdue} tone={overdue > 0 ? 'primary' : null} />
        </div>
    );
}

export default function ProjectsIndex({ projects, companies, metrics = null, archivedView = false }) {
    const [filter, setFilter] = useState('all');
    const [search, setSearch] = useState('');
    // List or Board, remembered in the browser. Archived projects are all
    // one status, so they're always a list.
    const [savedView, setView] = useRememberedTab('projects-view', VIEWS.map((v) => v.value));
    const view = archivedView ? 'list' : savedView;
    // Starred projects: each person's own, sorted first in both views, and
    // the Starred pill (remembered) narrows both to just them. The archive
    // has no stars.
    const favorites = useFavorites(projects);
    const canStar = !archivedView;
    const [savedStarFilter, setStarFilter] = useRememberedTab('projects-starred', ['all', 'starred']);
    const starredOnly = canStar && savedStarFilter === 'starred';
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(emptyForm());
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    // Search matches a project's name or its client's; it narrows the list,
    // the board and the archive alike. The status filter is the list's.
    const query = search.trim().toLowerCase();
    const matched = projects.filter((p) => (!query || p.name.toLowerCase().includes(query) || p.company?.name?.toLowerCase().includes(query))
        && (!starredOnly || favorites.isStarred(p)));
    // Starred first, otherwise in name order (as they arrive).
    const searchedProjects = canStar
        ? [...matched].sort((a, b) => Number(favorites.isStarred(b)) - Number(favorites.isStarred(a)))
        : matched;
    const visibleProjects = filter === 'all' ? searchedProjects : searchedProjects.filter((p) => p.status === filter);
    const emptyText = query
        ? `No projects match "${search.trim()}".`
        : starredOnly ? 'No starred projects here yet. Star a project to add it to this view.' : 'No projects match this filter.';
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
                    <ViewToggle views={VIEWS} value={view} onChange={setView} label="Projects view" />
                )}
            />

            {/* The list's summary; the board gives the space to its columns. */}
            {metrics && view === 'list' && <ProjectMetrics metrics={metrics} projects={projects} />}

            <div className="filter-bar">
                <input
                    type="search"
                    placeholder="Search projects or clients…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    aria-label="Search projects"
                    className="input filter-bar__search"
                />
                {/* Starred works in both views; the board's columns are the
                    statuses, so the status pills are the list's. */}
                {canStar && (
                    <div className="filter-bar__pills">
                        <button
                            onClick={() => setStarFilter(starredOnly ? 'all' : 'starred')}
                            aria-pressed={starredOnly}
                            title={starredOnly ? 'Show all projects' : 'Show starred projects only'}
                            aria-label="Starred projects only"
                            className={`filter-bar__pill filter-bar__pill--icon${starredOnly ? ' filter-bar__pill--active' : ''}`}
                        >
                            <Star weight={starredOnly ? 'fill' : 'regular'} />
                        </button>
                    </div>
                )}
                {!archivedView && view === 'list' && (
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
                )}
                {!archivedView && (
                    <Link href="/projects/archived" className="link link--muted filter-bar__link">
                        Archived
                    </Link>
                )}
            </div>

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

            {/* The add action, directly above the list or board it adds to. */}
            {!archivedView && <TabToolbar addLabel="New project" onAdd={() => setShowForm(true)} />}
            {view === 'board' ? (
                <ProjectBoard projects={searchedProjects} favorites={favorites} onChange={() => router.reload({ only: ['projects'] })} />
            ) : (
                <div className="card card--flush">
                    {visibleProjects.length === 0 ? (
                        <EmptyState text={emptyText} />
                    ) : (
                        <ProjectsTable projects={visibleProjects} favorites={canStar ? favorites : null} onChange={() => router.reload({ only: ['projects'] })} />
                    )}
                </div>
            )}
        </AppLayout>
    );
}

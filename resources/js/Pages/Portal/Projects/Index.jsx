import { Head } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '../../../Layouts/PortalLayout';
import PageHeader from '../../../Components/PageHeader';
import EmptyState from '../../../Components/EmptyState';
import MetricCard from '../../../Components/MetricCard';
import FilterBar from '../../../Components/FilterBar';
import ProjectsTable, { PROJECT_STATUS_OPTIONS } from '../../../Components/ProjectsTable';

// Client Hub home: the company's active projects and any awaiting a
// proposal, in the staff Projects list (read-only). Rows open the portal
// project page. Across the top: their active projects, the open tasks on
// them, and any proposals waiting on their review (red when there are).
export default function PortalProjectsIndex({ projects, awaitingProposals = 0, activeMessages = 0, activeMessagesDays = 7 }) {
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('all');

    // A pill per status these projects are in.
    const statuses = PROJECT_STATUS_OPTIONS.filter((s) => projects.some((p) => p.status === s.value));
    const filters = [{ value: 'all', label: 'All' }, ...statuses];

    const query = search.trim().toLowerCase();
    const visibleProjects = projects.filter((p) => (filter === 'all' || p.status === filter)
        && (!query || p.name.toLowerCase().includes(query)));

    const openTasks = projects.flatMap((p) => p.tasks).filter((t) => t.status !== 'done').length;

    return (
        <PortalLayout>
            <Head title="Projects" />
            <PageHeader title="Projects" />

            <div className="metric-grid">
                <MetricCard label="Active projects" value={projects.filter((p) => p.status === 'active').length} />
                <MetricCard label="Open tasks" value={openTasks} />
                {/* Conversations with a new message in the window (ActiveMessages). */}
                <MetricCard
                    label={`Active messages (last ${activeMessagesDays} days)`}
                    value={activeMessages}
                    tone={activeMessages > 0 ? 'primary' : null}
                />
                <MetricCard
                    label="Proposals awaiting your review"
                    value={awaitingProposals}
                    tone={awaitingProposals > 0 ? 'primary' : null}
                />
            </div>

            {projects.length > 0 && (
                <FilterBar
                    search={search}
                    onSearch={setSearch}
                    placeholder="Search projects…"
                    label="Search projects"
                    filters={filters}
                    value={filter}
                    onChange={setFilter}
                />
            )}

            <div className="card card--flush">
                {projects.length === 0 ? (
                    <EmptyState text="No active projects right now." />
                ) : visibleProjects.length === 0 ? (
                    <EmptyState text={query ? `No projects match "${search.trim()}".` : 'No projects match this filter.'} />
                ) : (
                    <ProjectsTable
                        projects={visibleProjects}
                        showClient={false}
                        canChangeStatus={false}
                        hrefFor={(project) => `/portal/projects/${project.id}`}
                    />
                )}
            </div>
        </PortalLayout>
    );
}

import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import EmptyState from '../../Components/EmptyState';
import { ProjectStatusBadge } from '../../Components/StatusBadges';
import PageHeader from '../../Components/PageHeader';

export default function TeamMemberDashboard({ projects, weekHours }) {
    return (
        <AppLayout>
            <Head title="Overview" />
            <PageHeader
                title="Overview"
                subtitle="Your projects and hours this week."
            />

            <div className="metric-grid">
                <MetricCard label="Hours this week" value={`${weekHours}h`} />
                <MetricCard label="Assigned projects" value={projects.length} />
            </div>

            <h2 className="section-heading">Your projects</h2>
            <div className="card">
                {projects.length === 0 ? (
                    <EmptyState text="You're not assigned to any projects yet." />
                ) : (
                    projects.map((project) => (
                        <Link
                            key={project.id}
                            href={`/projects/${project.id}`}
                            className="list-row"
                        >
                            <div>
                                <div className="list-row__title">{project.name}</div>
                                <div className="list-row__meta">{project.company.name}</div>
                            </div>
                            <ProjectStatusBadge project={project} />
                        </Link>
                    ))
                )}
            </div>
        </AppLayout>
    );
}

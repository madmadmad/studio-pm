import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import MetricCard from '../../Components/MetricCard';
import EmptyState from '../../Components/EmptyState';
import { ProjectStatusBadge } from '../../Components/StatusBadges';

export default function TeamMemberDashboard({ projects, weekHours }) {
    return (
        <AppLayout>
            <Head title="Overview" />
            <h1 className="font-display text-2xl font-semibold mb-1">Overview</h1>
            <p className="text-sm text-shadow-grey mb-6">Your projects and hours this week.</p>

            <div className="grid grid-cols-2 gap-4 mb-8">
                <MetricCard label="Hours this week" value={`${weekHours}h`} />
                <MetricCard label="Assigned projects" value={projects.length} />
            </div>

            <h2 className="text-sm font-semibold mb-3 text-shadow-grey">Your projects</h2>
            <div className="card">
                {projects.length === 0 ? (
                    <EmptyState text="You're not assigned to any projects yet." />
                ) : (
                    projects.map((project) => (
                        <Link
                            key={project.id}
                            href={`/projects/${project.id}`}
                            className="flex items-center justify-between px-4 py-3 border-b border-border last:border-b-0 hover:bg-porcelain"
                        >
                            <div>
                                <div className="text-sm font-medium">{project.name}</div>
                                <div className="text-xs text-shadow-grey">{project.company.name}</div>
                            </div>
                            <ProjectStatusBadge project={project} />
                        </Link>
                    ))
                )}
            </div>
        </AppLayout>
    );
}

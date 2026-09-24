import { Head, Link } from '@inertiajs/react';
import PortalLayout from '../../../Layouts/PortalLayout';
import EmptyState from '../../../Components/EmptyState';
import { ProjectStatusBadge } from '../../../Components/StatusBadges';
import PageHeader from '../../../Components/PageHeader';

export default function PortalProjectsIndex({ projects }) {
    return (
        <PortalLayout>
            <Head title="Your projects" />
            <PageHeader
                title="Your projects"
                subtitle={
                    <>
                        {projects.length} project{projects.length !== 1 ? 's' : ''}.
                    </>
                }
            />

            <div className="card">
                {projects.length === 0 ? (
                    <EmptyState text="No projects yet." />
                ) : (
                    projects.map((project) => (
                        <Link
                            key={project.id}
                            href={`/portal/projects/${project.id}`}
                            className="flex items-center justify-between px-4 py-3 border-b border-border last:border-b-0 hover:bg-porcelain"
                        >
                            <div className="text-sm font-medium">{project.name}</div>
                            <ProjectStatusBadge project={project} />
                        </Link>
                    ))
                )}
            </div>
        </PortalLayout>
    );
}

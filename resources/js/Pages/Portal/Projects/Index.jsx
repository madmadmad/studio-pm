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
                            className="list-row"
                        >
                            <div className="list-row__title">{project.name}</div>
                            <ProjectStatusBadge project={project} />
                        </Link>
                    ))
                )}
            </div>
        </PortalLayout>
    );
}

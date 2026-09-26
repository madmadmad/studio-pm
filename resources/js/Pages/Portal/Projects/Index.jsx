import { Head } from '@inertiajs/react';
import PortalLayout from '../../../Layouts/PortalLayout';
import PageHeader from '../../../Components/PageHeader';
import EmptyState from '../../../Components/EmptyState';
import ProjectsTable from '../../../Components/ProjectsTable';

// Client Hub home: the company's active projects and any awaiting a
// proposal, in the staff Projects list (read-only). Rows open the portal
// project page.
export default function PortalProjectsIndex({ projects }) {
    return (
        <PortalLayout>
            <Head title="Projects" />
            <PageHeader title="Projects" />

            <div className="card card--flush">
                {projects.length === 0 ? (
                    <EmptyState text="No active projects right now." />
                ) : (
                    <ProjectsTable
                        projects={projects}
                        showClient={false}
                        canChangeStatus={false}
                        hrefFor={(project) => `/portal/projects/${project.id}`}
                    />
                )}
            </div>
        </PortalLayout>
    );
}

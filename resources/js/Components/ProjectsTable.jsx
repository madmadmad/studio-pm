import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { ProjectStatusBadge } from './StatusBadges';
import StarButton from './StarButton';
import UnreadCount from './UnreadCount';
import { api } from '../lib/api';
import { visitRow } from '../lib/rowLink';
import { useListMotion } from '../lib/listMotion';

// The project list, shared by the Projects page and a client's page so the
// two look and behave the same (the Client Hub home too). `showClient` adds
// the Client column (off on a client's own page, where it would repeat the
// page). `hrefFor(project)` is where a row goes (staff project page by
// default). `canChangeStatus` shows the status dropdown (staff only);
// `onChange` runs after a status change so the caller can reload its data.
// `favorites` (useFavorites) adds a star before each name. A project's
// `unread_messages` shows as a red count after its name.

export const PROJECT_STATUS_OPTIONS = [
    { value: 'leads', label: 'Leads' },
    { value: 'estimated', label: 'Estimated' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'completed', label: 'Completed' },
    { value: 'archived', label: 'Archived' },
];

function taskProgress(project) {
    const total = project.tasks.length;
    if (total === 0) return '—';
    const done = project.tasks.filter((t) => t.status === 'done').length;
    return `${done}/${total} done`;
}

export default function ProjectsTable({ projects, showClient = true, hrefFor = (p) => `/projects/${p.id}`, canChangeStatus = true, onChange, favorites = null }) {
    const [pendingStatus, setPendingStatus] = useState({});
    const rowsRef = useListMotion();

    async function changeStatus(project, status) {
        setPendingStatus((current) => ({ ...current, [project.id]: true }));
        try {
            await api.patch(`/api/projects/${project.id}`, { status });
            onChange();
        } finally {
            setPendingStatus((current) => ({ ...current, [project.id]: false }));
        }
    }

    return (
        <table className="table">
            <thead>
                <tr>
                    <th>Project</th>
                    {showClient && <th>Client</th>}
                    <th>Tasks</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody ref={rowsRef}>
                {projects.map((project) => (
                    <tr key={project.id} onClick={(e) => visitRow(e, hrefFor(project))} className="table__row--link">
                        <td className="table__cell--strong">
                            <div className="table__group">
                                {favorites && <StarButton starred={favorites.isStarred(project)} onToggle={() => favorites.toggle(project)} />}
                                <Link href={hrefFor(project)} className="link">{project.name}</Link>
                                <UnreadCount count={project.unread_messages} />
                            </div>
                        </td>
                        {showClient && (
                            <td>
                                <Link href={`/clients/${project.company.id}`} className="link link--muted">
                                    {project.company.name}
                                </Link>
                            </td>
                        )}
                        <td className="table__cell--muted">{taskProgress(project)}</td>
                        <td>
                            <div className="table__group">
                                <ProjectStatusBadge project={project} />
                                {canChangeStatus && (
                                    <select
                                        value={project.status}
                                        disabled={pendingStatus[project.id]}
                                        onChange={(e) => changeStatus(project, e.target.value)}
                                        className="input input--micro input--inline"
                                    >
                                        {PROJECT_STATUS_OPTIONS.map((s) => (
                                            <option key={s.value} value={s.value}>{s.label}</option>
                                        ))}
                                    </select>
                                )}
                            </div>
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

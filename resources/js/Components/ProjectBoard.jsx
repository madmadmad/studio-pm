import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { api } from '../lib/api';

// The Projects page's board view: a column per status, each project a card
// (name, client, task progress). A card opens its project; dragging it to
// another column changes the project's status. Archived projects live on
// their own page, so there's no column for them.
export const BOARD_COLUMNS = [
    { value: 'leads', label: 'Leads' },
    { value: 'estimated', label: 'Estimated' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'completed', label: 'Completed' },
];

function taskProgress(project) {
    const total = project.tasks.length;
    if (total === 0) return 'No tasks';
    const done = project.tasks.filter((t) => t.status === 'done').length;
    return `${done}/${total} tasks done`;
}

export default function ProjectBoard({ projects, onChange }) {
    // A local copy so a dropped card moves immediately; re-synced on reload.
    const [items, setItems] = useState(projects);
    useEffect(() => setItems(projects), [projects]);
    const [dragId, setDragId] = useState(null);
    const [overColumn, setOverColumn] = useState(null);

    async function moveTo(status) {
        const project = items.find((p) => p.id === dragId);
        setOverColumn(null);
        if (!project || project.status === status) return;
        setItems((current) => current.map((p) => (p.id === project.id ? { ...p, status } : p)));
        try {
            await api.patch(`/api/projects/${project.id}`, { status });
        } catch (err) {
            alert(err.message || 'Could not move this project.');
        } finally {
            onChange();
        }
    }

    return (
        <div className="project-board">
            {BOARD_COLUMNS.map((column) => {
                const cards = items.filter((p) => p.status === column.value);
                return (
                    <section
                        key={column.value}
                        onDragOver={(e) => {
                            e.preventDefault();
                            setOverColumn(column.value);
                        }}
                        onDragLeave={(e) => {
                            if (!e.currentTarget.contains(e.relatedTarget)) setOverColumn(null);
                        }}
                        onDrop={() => moveTo(column.value)}
                        className={`project-board__column${overColumn === column.value && dragId ? ' project-board__column--over' : ''}`}
                    >
                        <h2 className="section-label section-label--ruled project-board__heading">
                            {column.label}
                            <span className="count">{cards.length}</span>
                        </h2>
                        <div className="project-board__cards">
                            {cards.map((project) => (
                                <div
                                    key={project.id}
                                    draggable
                                    onDragStart={() => setDragId(project.id)}
                                    onDragEnd={() => {
                                        setDragId(null);
                                        setOverColumn(null);
                                    }}
                                    onClick={(e) => {
                                        if (!e.target.closest('a')) router.visit(`/projects/${project.id}`);
                                    }}
                                    className={`card card--padded project-board__card${dragId === project.id ? ' project-board__card--dragging' : ''}`}
                                >
                                    <Link href={`/projects/${project.id}`} className="project-board__name" title={project.name}>{project.name}</Link>
                                    <Link href={`/clients/${project.company.id}`} className="link link--muted project-board__client" title={project.company.name}>
                                        {project.company.name}
                                    </Link>
                                    <div className="project-board__meta">{taskProgress(project)}</div>
                                </div>
                            ))}
                            {cards.length === 0 && <div className="project-board__empty">No projects</div>}
                        </div>
                    </section>
                );
            })}
        </div>
    );
}

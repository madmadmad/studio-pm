import { useEffect, useState } from 'react';
import { Star } from '@phosphor-icons/react';
import { api } from '../lib/api';

// The star that adds a project to the viewer's favorites: an outline in
// grey, filled red once starred. Stops the click there, so a star on a
// clickable row or card doesn't also open the project.
export default function StarButton({ starred, onToggle, size, className = '' }) {
    const label = starred ? 'Unstar project' : 'Star project';
    return (
        <button
            type="button"
            onClick={(e) => {
                e.stopPropagation();
                onToggle();
            }}
            title={label}
            aria-label={label}
            aria-pressed={starred}
            className={`icon-btn icon-btn--star${starred ? ' icon-btn--starred' : ''} ${className}`.trim()}
        >
            <Star size={size} weight={starred ? 'fill' : 'regular'} />
        </button>
    );
}

// Starring across a page's projects: the starred ids, changed at once on a
// click and put back if the server refuses. Seeded from each project's
// `is_favorite` (ProjectPageController) and re-seeded when they reload.
export function useFavorites(projects) {
    const seed = () => new Set(projects.filter((p) => p.is_favorite).map((p) => p.id));
    const [starred, setStarred] = useState(seed);
    useEffect(() => setStarred(seed()), [projects]); // eslint-disable-line react-hooks/exhaustive-deps

    function set(id, on) {
        setStarred((current) => {
            const next = new Set(current);
            if (on) next.add(id);
            else next.delete(id);
            return next;
        });
    }

    async function toggle(project) {
        const on = !starred.has(project.id);
        set(project.id, on);
        try {
            if (on) await api.post(`/api/projects/${project.id}/favorite`);
            else await api.delete(`/api/projects/${project.id}/favorite`);
        } catch {
            set(project.id, !on);
        }
    }

    return { isStarred: (project) => starred.has(project.id), toggle };
}

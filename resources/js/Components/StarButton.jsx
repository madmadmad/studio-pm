import { useEffect, useState } from 'react';
import { Star } from '@phosphor-icons/react';
import { api } from '../lib/api';

// The star that adds a project (or, with noun="task", a task) to the
// viewer's favorites: an outline in grey, filled red once starred. Stops
// the click there, so a star on a clickable row or card doesn't also open
// the item.
export default function StarButton({ starred, onToggle, size, noun = 'project', className = '' }) {
    const label = starred ? `Unstar ${noun}` : `Star ${noun}`;
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

// Starring across a page's projects (or tasks, with resource 'tasks'): the
// starred ids, changed at once on a click and put back if the server
// refuses. Seeded from each item's `is_favorite` (ProjectPageController,
// ProfilePageController) and re-seeded when they reload.
export function useFavorites(items, resource = 'projects') {
    const seed = () => new Set(items.filter((i) => i.is_favorite).map((i) => i.id));
    const [starred, setStarred] = useState(seed);
    useEffect(() => setStarred(seed()), [items]); // eslint-disable-line react-hooks/exhaustive-deps

    function set(id, on) {
        setStarred((current) => {
            const next = new Set(current);
            if (on) next.add(id);
            else next.delete(id);
            return next;
        });
    }

    async function toggle(item) {
        const on = !starred.has(item.id);
        set(item.id, on);
        try {
            if (on) await api.post(`/api/${resource}/${item.id}/favorite`);
            else await api.delete(`/api/${resource}/${item.id}/favorite`);
        } catch {
            set(item.id, !on);
        }
    }

    return { isStarred: (item) => starred.has(item.id), toggle };
}

import { Plus } from '@phosphor-icons/react';

// The bar above a tab's list: an optional summary on the left (in a
// .toolbar__summary: counts as .count circles, names, money and dates as
// .toolbar__figure) and the tab's one add
// action, a large plus, on the right (after any extra `actions`, such as
// the Schedule tab's "Build from services"). Every list tab follows the same
// pattern (project and client pages): this toolbar, the list, and
// create/view/edit in a drawer.
export default function TabToolbar({ summary, addLabel, onAdd, disabled, actions }) {
    const add = onAdd && (
        <button onClick={onAdd} disabled={disabled} title={addLabel} aria-label={addLabel} className="icon-btn icon-btn--secondary icon-btn--lg">
            <Plus />
        </button>
    );

    return (
        <div className={`toolbar${summary ? ' toolbar--split' : ''} page-section--tight`}>
            {summary}
            {actions ? <div className="toolbar__actions">{actions}{add}</div> : add}
        </div>
    );
}

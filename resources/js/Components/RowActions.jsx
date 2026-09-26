import { useState } from 'react';
import { CaretRight, Trash } from '@phosphor-icons/react';

// The end of a list row: a delete button (when the item can be deleted)
// and the "open" caret. The caret has no handler of its own -- its click
// bubbles to the row, which opens the drawer; it's the keyboard-reachable
// way in. Delete stops its click there, asks first with `confirmMessage`,
// then runs `onDelete` (which should refresh the list). Pass no onDelete
// for an item the API won't delete (accepted, paid, billed), and the
// column keeps its width so rows stay aligned.
export default function RowActions({ openLabel, deleteLabel, confirmMessage, onDelete }) {
    const [deleting, setDeleting] = useState(false);

    async function remove(e) {
        e.stopPropagation();
        if (deleting || !confirm(confirmMessage)) return;
        setDeleting(true);
        try {
            await onDelete();
        } catch (err) {
            alert(err.message || 'Could not delete this.');
        } finally {
            setDeleting(false);
        }
    }

    return (
        <div className="grid-row__actions">
            {onDelete && (
                <button onClick={remove} disabled={deleting} title={deleteLabel} className="icon-btn icon-btn--danger grid-row__delete">
                    <Trash />
                </button>
            )}
            <button title={openLabel} className="row-action">
                <CaretRight size={14} weight="bold" />
            </button>
        </div>
    );
}

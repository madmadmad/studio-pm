import { useEffect, useRef, useState } from 'react';

// An icon button that opens a small menu of actions (a contact card's
// settings). `items` are { label, onSelect, danger? }; falsy entries are
// skipped so callers can include items conditionally. Closes on an item,
// an outside click, or Escape.
export default function ActionMenu({ label, icon, items }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return;
        function close(e) {
            if (e.type === 'keydown' ? e.key === 'Escape' : !ref.current?.contains(e.target)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [open]);

    return (
        <div className="action-menu" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                title={label}
                aria-label={label}
                aria-haspopup="menu"
                aria-expanded={open}
                className="icon-btn icon-btn--secondary"
            >
                {icon}
            </button>
            {open && (
                <div className="popover action-menu__panel" role="menu">
                    {items.filter(Boolean).map((item) => (
                        <button
                            key={item.label}
                            type="button"
                            role="menuitem"
                            onClick={() => {
                                setOpen(false);
                                item.onSelect();
                            }}
                            className={`action-menu__item${item.danger ? ' action-menu__item--danger' : ''}`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

import { Children, useEffect, useRef, useState } from 'react';
import { CalendarBlank, X } from '@phosphor-icons/react';
import { formatDate } from '../lib/format';

// Every record's drawer opens the same way (modeled on the task drawer):
//
//   <DrawerByline>                 small grey line: a date, then any
//     <DrawerDate label="Created" date={...} />   status or people,
//     <StatusBadge ... />                         separated by "·"
//   </DrawerByline>
//   title                          .inline-edit--title when editable,
//                                  otherwise <h2 className="drawer__title">
//
// A create-mode drawer ("Log time") has no record yet, so it opens with
// just the .drawer__title heading.

// "Created Sep 19, 2026", led by a small fern calendar icon.
export function DrawerDate({ label, date }) {
    return (
        <span className="drawer__date">
            <CalendarBlank className="drawer__date-icon" aria-hidden="true" />
            {label} {formatDate(date)}
        </span>
    );
}

// The meta line above a drawer's title. Each child is one item; falsy
// children are skipped, and a "·" goes between the rest.
export function DrawerByline({ children }) {
    const items = Children.toArray(children).filter(Boolean);

    return (
        <div className="drawer__meta drawer__byline">
            {items.map((item, i) => (
                <span key={i} className="drawer__byline-item">
                    {i > 0 && <span aria-hidden="true">·</span>}
                    {item}
                </span>
            ))}
        </div>
    );
}

// Right-edge slide-in panel. `actions` (icon buttons, given the
// `drawer__action` class, or small text actions) sit to the left of the
// built-in close button, pinned to the panel's top-right corner
// independent of the body padding. The caller owns the open/closed state;
// the drawer handles Escape, the close button and backdrop clicks itself,
// playing its exit animation before calling `onClose`. A caller that calls
// its own close directly (e.g. after a delete) closes it instantly.
// `size="wide"` uses --size-drawer-wide (proposals, invoices) instead of
// the standard --size-drawer.
export default function Drawer({ actions, onClose, size, children }) {
    const [closing, setClosing] = useState(false);
    const closingRef = useRef(false);
    const panelRef = useRef(null);
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;

    function requestClose() {
        if (closingRef.current) return;
        closingRef.current = true;
        // Commit fields that save on blur (titles, descriptions, hours):
        // unmounting a focused input never fires its blur.
        if (panelRef.current?.contains(document.activeElement)) {
            document.activeElement.blur();
        }
        setClosing(true);
    }

    useEffect(() => {
        // An overlay opened from inside the drawer (a message's image
        // lightbox, the send-invoice modal) owns Escape while it's up --
        // one press closes only the top layer.
        function onKeyDown(e) {
            if (e.key !== 'Escape') return;
            if (panelRef.current?.querySelector('.lightbox, .modal')) return;
            requestClose();
        }
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    // Hand back to the caller once the exit animation has run. Timed from
    // the panel's computed animation, so it follows --duration-drawer and
    // needs no animationend event (which wouldn't fire with no animation).
    useEffect(() => {
        if (!closing) return;
        const style = getComputedStyle(panelRef.current);
        const ms = style.animationName === 'none' ? 0 : parseFloat(style.animationDuration) * 1000;
        const timer = setTimeout(() => onCloseRef.current(), ms);
        return () => clearTimeout(timer);
    }, [closing]);

    return (
        <div className={`drawer${size === 'wide' ? ' drawer--wide' : ''}${closing ? ' drawer--closing' : ''}`}>
            <div className="drawer__backdrop" onClick={requestClose} />
            <div className="drawer__panel" ref={panelRef}>
                <div className="drawer__actions">
                    {actions}
                    <button onClick={requestClose} className="icon-btn icon-btn--secondary drawer__action">
                        <X />
                    </button>
                </div>
                <div className="drawer__body">{children}</div>
            </div>
        </div>
    );
}

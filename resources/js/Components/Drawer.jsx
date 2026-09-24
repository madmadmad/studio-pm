import { useEffect, useRef, useState } from 'react';
import { X } from '@phosphor-icons/react';

// Right-edge slide-in panel. `header` sits on the left of the top bar;
// `actions` (icon buttons, given the `drawer__action` class) sit to the
// left of the built-in close button. The caller owns the open/closed
// state; the drawer handles Escape, the close button and backdrop clicks
// itself, playing its exit animation before calling `onClose`. A caller
// that calls its own close directly (e.g. after a delete) closes it
// instantly.
export default function Drawer({ header, actions, onClose, children }) {
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
        function onKeyDown(e) {
            if (e.key === 'Escape') requestClose();
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
        <div className={`drawer${closing ? ' drawer--closing' : ''}`}>
            <div className="drawer__backdrop" onClick={requestClose} />
            <div className="drawer__panel" ref={panelRef}>
                <div className="drawer__header">
                    {header}
                    <div className="drawer__actions">
                        {actions}
                        <button onClick={requestClose} className="icon-btn icon-btn--secondary drawer__action">
                            <X />
                        </button>
                    </div>
                </div>
                <div className="drawer__body">{children}</div>
            </div>
        </div>
    );
}

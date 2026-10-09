import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Smiley } from '@phosphor-icons/react';
import EmojiGrid from './EmojiGrid';

const GAP = 6; // px between the button and the panel

// A smiley button that opens the full emoji picker (EmojiGrid) beside it.
// The panel is drawn at the page level (a portal), positioned from the
// button, so a scrolling message list can't clip it: above the button when
// there's more room there, else below, kept inside the window. `align`
// lines it up with the button's left or right edge. Closes on a pick, a
// click outside, or Escape.
export default function EmojiPopover({ onPick, label = 'Add emoji', size = 20, align = 'right' }) {
    const [open, setOpen] = useState(false);
    const [style, setStyle] = useState(null);
    const button = useRef(null);
    const panel = useRef(null);

    useLayoutEffect(() => {
        if (!open || !button.current || !panel.current) return;
        const b = button.current.getBoundingClientRect();
        const p = panel.current.getBoundingClientRect();
        const above = b.top - GAP - p.height;
        const top = above >= 0 && b.top > window.innerHeight - b.bottom ? above : Math.min(b.bottom + GAP, window.innerHeight - p.height - GAP);
        const left = align === 'right' ? b.right - p.width : b.left;
        setStyle({ top: Math.max(GAP, top), left: Math.max(GAP, Math.min(left, window.innerWidth - p.width - GAP)) });
    }, [open, align]);

    useEffect(() => {
        if (!open) return undefined;
        const inside = (target) => button.current?.contains(target) || panel.current?.contains(target);
        const onPointerDown = (e) => !inside(e.target) && setOpen(false);
        const onKeyDown = (e) => {
            if (e.key === 'Escape') {
                e.stopPropagation();
                setOpen(false);
                button.current?.focus();
            }
        };
        const onResize = () => setOpen(false);
        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown, true);
        window.addEventListener('resize', onResize);
        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown, true);
            window.removeEventListener('resize', onResize);
        };
    }, [open]);

    function toggle() {
        setStyle(null);
        setOpen((o) => !o);
    }

    return (
        <span className="emoji-popover">
            <button
                ref={button}
                type="button"
                onClick={toggle}
                title={label}
                aria-label={label}
                aria-expanded={open}
                className="icon-btn icon-btn--secondary"
            >
                <Smiley size={size} />
            </button>
            {open && createPortal(
                <div
                    ref={panel}
                    role="dialog"
                    aria-label={label}
                    className="popover emoji-popover__panel"
                    // Measured first (hidden), then placed.
                    style={style ?? { visibility: 'hidden', top: 0, left: 0 }}
                >
                    <EmojiGrid onPick={(emoji) => { onPick(emoji); setOpen(false); }} />
                </div>,
                document.body,
            )}
        </span>
    );
}

import { useEffect, useRef, useState } from 'react';
import { Smiley } from '@phosphor-icons/react';

// The eight reactions a message can take -- matches MessageReaction::EMOJI
// on the server, which only accepts these.
export const REACTION_EMOJI = ['👍', '❤️', '😂', '🎉', '👀', '🙏', '✅', '🔥'];

// Emoji to type into a message: the reactions first, then common faces,
// hands and objects.
export const COMPOSER_EMOJI = [
    ...REACTION_EMOJI,
    '😀', '😄', '😊', '🙂', '😉', '😍', '🤔', '😅',
    '😬', '😮', '😢', '😎', '🥳', '🤩', '🙌', '👏',
    '👋', '🤝', '💪', '👌', '✌️', '🤞', '👎', '💡',
    '⭐', '✨', '💯', '⚡', '🚀', '📌', '📎', '📅',
    '⏰', '✏️', '💬', '❗', '❓', '⚠️', '☕', '🍕',
];

// A smiley button that opens a small grid of emoji; `onPick(emoji)` runs
// with the one chosen, and the grid closes. `emojis` picks the set (the
// reactions, or the wider composer set); `label` is the button's tooltip.
// `placement` opens the grid below the button ('down') or above it ('up',
// for a composer at the bottom of a drawer); `align` pins it to the
// button's left or right edge. Closes on a click outside or Escape.
export default function EmojiPicker({ emojis = COMPOSER_EMOJI, onPick, label = 'Add emoji', size = 20, align = 'left', placement = 'down' }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return;
        function onPointerDown(e) {
            if (!ref.current?.contains(e.target)) setOpen(false);
        }
        function onKeyDown(e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                setOpen(false);
            }
        }
        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown, true);
        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown, true);
        };
    }, [open]);

    function pick(emoji) {
        onPick(emoji);
        setOpen(false);
    }

    return (
        <div ref={ref} className="emoji-picker">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                title={label}
                aria-label={label}
                aria-expanded={open}
                className="icon-btn icon-btn--secondary"
            >
                <Smiley size={size} />
            </button>
            {open && (
                <div role="menu" className={`popover popover--padded emoji-picker__panel emoji-picker__panel--${align} emoji-picker__panel--${placement}`}>
                    {emojis.map((emoji) => (
                        <button key={emoji} type="button" role="menuitem" onClick={() => pick(emoji)} className="emoji-picker__emoji">
                            {emoji}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

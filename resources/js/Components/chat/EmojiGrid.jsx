import { useState } from 'react';
import { EmojiPicker } from 'frimousse';
import { recentEmoji, rememberEmoji } from '../../lib/emojiRecents';

const COLUMNS = 9;

// Every emoji (Frimousse, from Emojibase): search, your recent picks, the
// categories, and a skin tone for the ones that take one. `onPick(emoji)`
// gets the character. Styled by .emoji-grid -- Frimousse brings no styles
// of its own. Its data loads from the Emojibase CDN the first time a
// picker opens, then comes from the browser's cache.
export default function EmojiGrid({ onPick, autoFocus = true }) {
    const [recent] = useState(recentEmoji);

    function pick(emoji) {
        rememberEmoji(emoji);
        onPick(emoji);
    }

    return (
        <EmojiPicker.Root className="emoji-grid" columns={COLUMNS} onEmojiSelect={({ emoji }) => pick(emoji)}>
            <EmojiPicker.Search className="input emoji-grid__search" placeholder="Search emoji" aria-label="Search emoji" autoFocus={autoFocus} />
            {recent.length > 0 && (
                <div className="emoji-grid__recent" aria-label="Recently used">
                    {recent.slice(0, COLUMNS).map((emoji) => (
                        <button key={emoji} type="button" className="emoji-grid__emoji" onClick={() => pick(emoji)}>{emoji}</button>
                    ))}
                </div>
            )}
            <EmojiPicker.Viewport className="emoji-grid__viewport">
                <EmojiPicker.Loading className="emoji-grid__status">Loading…</EmojiPicker.Loading>
                <EmojiPicker.Empty className="emoji-grid__status">No emoji found.</EmojiPicker.Empty>
                <EmojiPicker.List
                    className="emoji-grid__list"
                    components={{
                        CategoryHeader: ({ category, ...props }) => <div {...props} className="emoji-grid__category">{category.label}</div>,
                        Row: ({ children, ...props }) => <div {...props} className="emoji-grid__row">{children}</div>,
                        Emoji: ({ emoji, ...props }) => <button {...props} className="emoji-grid__emoji">{emoji.emoji}</button>,
                    }}
                />
            </EmojiPicker.Viewport>
            <div className="emoji-grid__footer">
                <EmojiPicker.ActiveEmoji>
                    {({ emoji }) => (
                        <span className="emoji-grid__preview">
                            {emoji ? <><span className="emoji-grid__preview-emoji">{emoji.emoji}</span>{emoji.label}</> : 'Pick an emoji…'}
                        </span>
                    )}
                </EmojiPicker.ActiveEmoji>
                <EmojiPicker.SkinToneSelector className="emoji-grid__skin-tone" title="Skin tone" />
            </div>
        </EmojiPicker.Root>
    );
}

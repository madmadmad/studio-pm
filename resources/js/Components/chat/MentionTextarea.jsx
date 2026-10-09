import { forwardRef, useImperativeHandle, useLayoutEffect, useRef, useState } from 'react';
import Avatar from '../Avatar';
import { mentionQueryAt } from '../../lib/chatText';

const MAX_SUGGESTIONS = 6;

// Chat's text field: grows with what's typed (then scrolls), and typing
// "@" offers the conversation's people -- arrow keys or the pointer to
// pick, Enter or Tab to put "@Their Name" in, Escape to dismiss. Each pick
// is reported through `onMention`, so the composer can store it as a
// <@id> token. Enter on its own is `onSubmit` (Shift+Enter is a new line);
// Escape with no suggestions open is `onCancel`.
const MentionTextarea = forwardRef(function MentionTextarea(
    { value, onChange, onMention, members, onSubmit, onCancel, onPaste, placeholder, autoFocus, maxLength, className = '' },
    ref,
) {
    const textarea = useRef(null);
    const [query, setQuery] = useState(null); // { start, query } while an @ is being typed
    const [highlight, setHighlight] = useState(0);
    const caretAfter = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => textarea.current?.focus(),
        // Puts text in at the caret (an emoji from the picker).
        insert(text) {
            const el = textarea.current;
            const start = el ? el.selectionStart : value.length;
            const end = el ? el.selectionEnd : value.length;
            caretAfter.current = start + text.length;
            onChange(value.slice(0, start) + text + value.slice(end));
        },
    }));

    // Grow to fit (the stylesheet caps it), and put the caret back after an
    // insert or a picked mention.
    useLayoutEffect(() => {
        const el = textarea.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${el.scrollHeight}px`;
        if (caretAfter.current != null) {
            el.focus();
            el.setSelectionRange(caretAfter.current, caretAfter.current);
            caretAfter.current = null;
        }
    }, [value]);

    const needle = query?.query.toLowerCase() ?? '';
    const suggestions = query
        ? members.filter((m) => m.name.toLowerCase().split(/\s+/).some((part) => part.startsWith(needle)) || m.name.toLowerCase().startsWith(needle)).slice(0, MAX_SUGGESTIONS)
        : [];
    const open = suggestions.length > 0;

    function updateQuery(text, caret) {
        const next = mentionQueryAt(text, caret);
        setQuery(next);
        if (next?.query !== query?.query) setHighlight(0);
    }

    function pick(member) {
        const insert = `@${member.name} `;
        const caret = textarea.current?.selectionStart ?? value.length;
        caretAfter.current = query.start + insert.length;
        onChange(value.slice(0, query.start) + insert + value.slice(caret));
        onMention?.({ id: member.id, name: member.name });
        setQuery(null);
    }

    function onKeyDown(e) {
        if (open) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const step = e.key === 'ArrowDown' ? 1 : -1;
                setHighlight((h) => (h + step + suggestions.length) % suggestions.length);
                return;
            }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                pick(suggestions[highlight] ?? suggestions[0]);
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                setQuery(null);
                return;
            }
        }
        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
            e.preventDefault();
            onSubmit?.();
        } else if (e.key === 'Escape' && onCancel) {
            e.preventDefault();
            onCancel();
        }
    }

    return (
        <div className="mention-field">
            <textarea
                ref={textarea}
                value={value}
                rows={1}
                maxLength={maxLength}
                placeholder={placeholder}
                autoFocus={autoFocus}
                onChange={(e) => {
                    onChange(e.target.value);
                    updateQuery(e.target.value, e.target.selectionStart);
                }}
                onKeyDown={onKeyDown}
                onKeyUp={(e) => ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key) && updateQuery(value, e.target.selectionStart)}
                onClick={(e) => updateQuery(value, e.target.selectionStart)}
                onBlur={() => setTimeout(() => setQuery(null), 150)}
                onPaste={onPaste}
                role="combobox"
                aria-expanded={open}
                aria-autocomplete="list"
                aria-controls={open ? 'mention-suggestions' : undefined}
                className={`input mention-field__input ${className}`.trim()}
            />
            {open && (
                <ul id="mention-suggestions" role="listbox" className="popover mention-field__menu">
                    {suggestions.map((member, i) => (
                        <li
                            key={member.id}
                            role="option"
                            aria-selected={i === highlight}
                            onMouseDown={(e) => { e.preventDefault(); pick(member); }}
                            onMouseEnter={() => setHighlight(i)}
                            className={`mention-field__option${i === highlight ? ' mention-field__option--active' : ''}`}
                        >
                            <Avatar name={member.name} avatarUrl={member.avatar_url} id={member.id} size={20} />
                            <span className="mention-field__name">{member.name}</span>
                            {member.job_title && <span className="mention-field__meta">{member.job_title}</span>}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
});

export default MentionTextarea;

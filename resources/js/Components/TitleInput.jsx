import { useEffect, useRef } from 'react';

// A drawer's editable title (.inline-edit--title) that wraps onto as many
// lines as it needs, where an <input> would cut a long title off. A
// textarea that grows with its content, but it behaves as a one-line
// field: Enter commits (blurs, so the caller's onBlur saves) instead of
// adding a line, and a pasted line break becomes a space.
export default function TitleInput({ ref, value, onChange, onKeyDown, className = '', ...props }) {
    const inner = useRef(null);

    const setRef = (el) => {
        inner.current = el;
        if (typeof ref === 'function') ref(el);
        else if (ref) ref.current = el;
    };

    useEffect(() => fit(inner.current), [value]);

    // Refit when the width changes (the drawer opening or resizing, a
    // phone rotating) and once the webfont has loaded, since either can
    // move where the lines break.
    useEffect(() => {
        const el = inner.current;
        if (!el) return undefined;
        let width = el.clientWidth;
        const observer = new ResizeObserver(() => {
            if (el.clientWidth !== width) {
                width = el.clientWidth;
                fit(el);
            }
        });
        observer.observe(el);
        document.fonts?.ready.then(() => fit(el));
        return () => observer.disconnect();
    }, []);

    return (
        <textarea
            ref={setRef}
            rows={1}
            value={value}
            onChange={(e) => {
                if (/[\r\n]/.test(e.target.value)) e.target.value = e.target.value.replace(/[\r\n]+/g, ' ');
                onChange?.(e);
            }}
            onKeyDown={(e) => {
                if (e.key === 'Enter' && !e.nativeEvent.isComposing) {
                    e.preventDefault();
                    e.currentTarget.blur();
                }
                onKeyDown?.(e);
            }}
            className={`autosize ${className}`.trim()}
            {...props}
        />
    );
}

// Height to fit the text, plus the borders (scrollHeight leaves them out).
function fit(el) {
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight + el.offsetHeight - el.clientHeight}px`;
}

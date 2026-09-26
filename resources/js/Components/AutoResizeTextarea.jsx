import { useEffect, useRef } from 'react';

// A one-row textarea that grows with its content (drawer descriptions,
// notes). Styled by .autosize plus whatever `className` adds.
export default function AutoResizeTextarea({ value, className, ...props }) {
    const ref = useRef(null);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${el.scrollHeight}px`;
    }, [value]);

    return (
        <textarea
            ref={ref}
            value={value}
            rows={1}
            className={`autosize ${className}`}
            {...props}
        />
    );
}

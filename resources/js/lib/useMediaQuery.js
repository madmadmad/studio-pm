import { useEffect, useState } from 'react';

// Whether a CSS media query matches, kept up to date as the window changes.
// For behavior that differs by screen size (layout itself stays in CSS --
// match abstracts/_breakpoints: sm 40rem, md 48rem, lg 64rem, xl 80rem).
export function useMediaQuery(query) {
    const [matches, setMatches] = useState(() => window.matchMedia(query).matches);

    useEffect(() => {
        const list = window.matchMedia(query);
        const onChange = () => setMatches(list.matches);
        onChange();
        list.addEventListener('change', onChange);
        return () => list.removeEventListener('change', onChange);
    }, [query]);

    return matches;
}

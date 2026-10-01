import { useAutoAnimate } from '@formkit/auto-animate/react';

// A little motion when a list's rows change -- filtered out, searched in,
// re-sorted, moved between board columns: rows fade in and out and slide
// to their new places. Short and on the standard easing (--ease-standard),
// and skipped for anyone whose system asks for reduced motion. Put the
// returned ref on the element whose direct children are the rows.
export function useListMotion() {
    const [ref] = useAutoAnimate({ duration: 180, easing: 'cubic-bezier(0.4, 0, 0.2, 1)' });
    return ref;
}

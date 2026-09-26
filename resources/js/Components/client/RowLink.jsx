import { Link } from '@inertiajs/react';

// A table row's own link (its first column): an Inertia <Link>, or a plain
// <a> opening a new tab when the row does (see visitRow's `newTab`).
export default function RowLink({ newTab = false, children, ...props }) {
    return newTab
        ? <a target="_blank" rel="noopener noreferrer" {...props}>{children}</a>
        : <Link {...props}>{children}</Link>;
}

import { Link } from '@inertiajs/react';

// A table row's own link (its first column): an Inertia <Link>, or a plain
// <a> opening a new tab when the row does (see visitRow's `newTab`). With
// `onOpen` the row opens a drawer instead, so this is a button -- still
// the keyboard way in, styled like the link.
export default function RowLink({ newTab = false, onOpen, href, children, ...props }) {
    if (onOpen) return <button type="button" onClick={onOpen} {...props}>{children}</button>;
    return newTab
        ? <a target="_blank" rel="noopener noreferrer" href={href} {...props}>{children}</a>
        : <Link href={href} {...props}>{children}</Link>;
}

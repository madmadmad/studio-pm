import { router } from '@inertiajs/react';

// For a table row that opens a page when clicked anywhere (with the
// .table__row--link class). Clicks on the row's own controls -- links,
// buttons, dropdowns -- are left to those controls. `newTab` opens it in a
// new tab instead (a client opening an invoice or proposal from the
// Client Hub, so the hub stays open behind it).
export function visitRow(e, href, { newTab = false } = {}) {
    if (e.target.closest('a, button, select, input')) return;
    if (newTab) {
        window.open(href, '_blank', 'noopener');
    } else {
        router.visit(href);
    }
}

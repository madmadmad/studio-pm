import { router } from '@inertiajs/react';

// For a table row that opens a page when clicked anywhere (with the
// .table__row--link class). Clicks on the row's own controls -- links,
// buttons, dropdowns -- are left to those controls.
export function visitRow(e, href) {
    if (e.target.closest('a, button, select, input')) return;
    router.visit(href);
}

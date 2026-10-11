import { usePage } from '@inertiajs/react';

// Whether the signed-in staff member has a permission (config/permissions.php):
// all_projects, manage_projects, clients, proposals, invoices, expenses,
// bookkeeping, services -- and, for super admins only, settings and team.
// For showing and hiding things; the server enforces the same rules.
export function hasPermission(user, permission) {
    return Boolean(user?.permissions?.includes(permission));
}

export function useCan() {
    const user = usePage().props.auth?.user;
    return (permission) => hasPermission(user, permission);
}

// The studio's accountant (User::ROLE_ACCOUNTANT): Bookkeeping, and
// invoices and expenses to look at -- read-only, but for journal entries.
export function isAccountant(user) {
    return user?.role === 'accountant';
}

// Whether the page shows only what can be looked at, not changed: true for
// the accountant. For hiding the controls; the server refuses the changes.
export function useReadOnly() {
    return isAccountant(usePage().props.auth?.user);
}

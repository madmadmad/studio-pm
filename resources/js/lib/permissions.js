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

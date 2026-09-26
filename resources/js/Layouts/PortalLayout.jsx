import { usePage } from '@inertiajs/react';
import AppShell from './AppShell';

// The Client Hub frame: the same sidebar as the staff app, with the
// client's sections. Invoices only appear for billing and primary
// contacts (auth.user.can_view_invoices, shared by HandleInertiaRequests).
export default function PortalLayout({ children }) {
    const contact = usePage().props.auth?.user;

    const navItems = [
        { href: '/portal', label: 'Projects', exact: true, also: ['/portal/projects'] },
        { href: '/portal/proposals', label: 'Proposals' },
        ...(contact?.can_view_invoices ? [{ href: '/portal/invoices', label: 'Invoices' }] : []),
        { href: '/portal/contacts', label: 'Contacts' },
    ];

    return (
        <AppShell navItems={navItems} profileHref="/portal/profile" logoutHref="/portal/logout" logoutLabel="Sign out">
            {children}
        </AppShell>
    );
}

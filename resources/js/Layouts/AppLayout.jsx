import { usePage } from '@inertiajs/react';
import AlertsMenu from '../Components/AlertsMenu';
import AppShell from './AppShell';
import { hasPermission } from '../lib/permissions';

const NAV_ITEMS = [
    { href: '/', label: 'Overview', exact: true },
    { href: '/clients', label: 'Clients', permission: 'clients' },
    { href: '/projects', label: 'Projects' },
    // Time and Timesheets (/time-entries, /timesheets) are hidden while time
    // is logged only inside projects (each project's Time tab). The pages
    // still exist; add them back here to show them again.
    { href: '/invoices', label: 'Invoices', permission: 'invoices' },
    { href: '/proposals', label: 'Proposals', permission: 'proposals' },
    { href: '/bookkeeping', label: 'Bookkeeping', permission: 'bookkeeping' },
    { href: '/expenses', label: 'Expenses', permission: 'expenses' },
    { href: '/services', label: 'Services', permission: 'services' },
    { href: '/users', label: 'Team', permission: 'team' },
    { href: '/settings', label: 'Settings', permission: 'settings' },
];

// The staff app frame: the shared sidebar with the staff nav.
export default function AppLayout({ children }) {
    const { props } = usePage();
    const user = props.auth?.user;

    return (
        <AppShell
            navItems={NAV_ITEMS.filter((item) => !item.permission || hasPermission(user, item.permission))}
            // Alerts are failed or skipped invoice sends.
            footerExtra={hasPermission(user, 'invoices') && <AlertsMenu />}
            profileHref="/profile"
            logoutHref="/logout"
        >
            {children}
        </AppShell>
    );
}

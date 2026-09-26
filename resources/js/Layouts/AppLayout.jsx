import { usePage } from '@inertiajs/react';
import AlertsMenu from '../Components/AlertsMenu';
import AppShell from './AppShell';

const NAV_ITEMS = [
    { href: '/', label: 'Overview', exact: true },
    { href: '/clients', label: 'Clients', managerOnly: true },
    { href: '/projects', label: 'Projects' },
    { href: '/time-entries', label: 'Time' },
    { href: '/timesheets', label: 'Timesheets' },
    { href: '/invoices', label: 'Invoices', managerOnly: true },
    { href: '/proposals', label: 'Proposals', managerOnly: true },
    { href: '/bookkeeping', label: 'Bookkeeping', managerOnly: true },
    { href: '/expenses', label: 'Expenses', managerOnly: true },
    { href: '/services', label: 'Services', managerOnly: true },
    { href: '/users', label: 'Team', managerOnly: true },
    { href: '/settings', label: 'Settings', managerOnly: true },
];

// The staff app frame: the shared sidebar with the staff nav.
export default function AppLayout({ children }) {
    const { props } = usePage();
    const isManager = props.auth?.user?.role === 'manager';

    return (
        <AppShell
            navItems={NAV_ITEMS.filter((item) => !item.managerOnly || isManager)}
            extraNav={isManager && (
                <AlertsMenu
                    triggerClassName="app-shell__nav-link"
                    activeTriggerClassName="app-shell__nav-link--active"
                />
            )}
            profileHref="/profile"
            logoutHref="/logout"
        >
            {children}
        </AppShell>
    );
}

import { Link, router, usePage } from '@inertiajs/react';
import Avatar from '../Components/Avatar';
import NotificationBell from '../Components/NotificationBell';

const NAV_ITEMS = [
    { href: '/', label: 'Overview' },
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

export default function AppLayout({ children }) {
    const { url, props } = usePage();
    const user = props.auth?.user;
    const isManager = user?.role === 'manager';
    const navItems = NAV_ITEMS.filter((item) => !item.managerOnly || isManager);

    function isActive(href) {
        if (href === '/') return url === '/';
        return url.startsWith(href);
    }

    function handleLogout(e) {
        e.preventDefault();
        router.post('/logout');
    }

    return (
        <div className="app-shell">
            <aside className="app-shell__sidebar">
                <div className="app-shell__brand">
                    <div>
                        <div className="app-shell__brand-name">Studio PM</div>
                        <div className="app-shell__brand-tagline">Client and billing workspace</div>
                    </div>
                    {isManager && <NotificationBell />}
                </div>
                {navItems.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={`app-shell__nav-link${isActive(item.href) ? ' app-shell__nav-link--active' : ''}`}
                    >
                        {item.label}
                    </Link>
                ))}
                <div className="app-shell__footer">
                    {user && (
                        <Link href="/profile" className="app-shell__user">
                            <Avatar name={user.name} avatarUrl={user.avatar_url} id={user.id} size={28} />
                            <div className="app-shell__user-details">
                                <div className="app-shell__user-name">{user.name}</div>
                                <div className="app-shell__user-email">{user.email}</div>
                            </div>
                        </Link>
                    )}
                    <a href="/logout" onClick={handleLogout} className="app-shell__logout">
                        Log out
                    </a>
                </div>
            </aside>

            <main className="app-shell__main">{children}</main>
        </div>
    );
}

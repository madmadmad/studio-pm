import { Link, router, usePage } from '@inertiajs/react';

const NAV_ITEMS = [
    { href: '/', label: 'Overview' },
    { href: '/clients', label: 'Clients', managerOnly: true },
    { href: '/projects', label: 'Projects' },
    { href: '/time-entries', label: 'Time' },
    { href: '/timesheets', label: 'Timesheets' },
    { href: '/invoices', label: 'Invoices', managerOnly: true },
    { href: '/proposals', label: 'Proposals', managerOnly: true },
    { href: '/bookkeeping', label: 'Bookkeeping', managerOnly: true },
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
        <div className="flex min-h-screen bg-porcelain text-gunmetal">
            <aside className="w-56 flex-shrink-0 p-5 flex flex-col gap-1 bg-gunmetal">
                <div className="mb-6">
                    <div className="text-lg font-semibold text-porcelain">Studio PM</div>
                    <div className="text-xs text-porcelain/50">Client and billing workspace</div>
                </div>
                {navItems.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={`text-left px-3 py-2 rounded text-sm font-medium transition-colors ${
                            isActive(item.href) ? 'bg-[#373B45] text-watermelon' : 'text-porcelain/60 hover:text-porcelain'
                        }`}
                    >
                        {item.label}
                    </Link>
                ))}
                <div className="mt-auto pt-4 border-t border-porcelain/15">
                    {user && <div className="text-xs text-porcelain/50 mb-2 truncate">{user.email}</div>}
                    <a href="/logout" onClick={handleLogout} className="text-sm text-porcelain/60 hover:text-porcelain">
                        Log out
                    </a>
                </div>
            </aside>

            <main className="flex-1 min-w-0 p-8">{children}</main>
        </div>
    );
}

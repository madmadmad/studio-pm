import { Link, router, usePage } from '@inertiajs/react';

const NAV_ITEMS = [
    { href: '/', label: 'Overview' },
    { href: '/clients', label: 'Clients' },
    { href: '/projects', label: 'Projects' },
    { href: '/time-entries', label: 'Time' },
    { href: '/timesheets', label: 'Timesheets' },
    { href: '/invoices', label: 'Invoices' },
    { href: '/proposals', label: 'Proposals' },
    { href: '/bookkeeping', label: 'Bookkeeping' },
    { href: '/services', label: 'Services' },
];

export default function AppLayout({ children }) {
    const { url, props } = usePage();
    const user = props.auth?.user;

    function isActive(href) {
        if (href === '/') return url === '/';
        return url.startsWith(href);
    }

    function handleLogout(e) {
        e.preventDefault();
        router.post('/logout');
    }

    return (
        <div className="flex min-h-screen bg-paper text-ink">
            <aside className="w-56 flex-shrink-0 p-5 flex flex-col gap-1 bg-ink">
                <div className="mb-6">
                    <div className="text-lg font-semibold text-paper">Studio PM</div>
                    <div className="text-xs text-paper/50">Client and billing workspace</div>
                </div>
                {NAV_ITEMS.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={`text-left px-3 py-2 rounded text-sm font-medium transition-colors ${
                            isActive(item.href) ? 'bg-[#373B45] text-brass' : 'text-paper/60 hover:text-paper'
                        }`}
                    >
                        {item.label}
                    </Link>
                ))}
                <div className="mt-auto pt-4 border-t border-paper/15">
                    {user && <div className="text-xs text-paper/50 mb-2 truncate">{user.email}</div>}
                    <a href="/logout" onClick={handleLogout} className="text-sm text-paper/60 hover:text-paper">
                        Log out
                    </a>
                </div>
            </aside>

            <main className="flex-1 p-8 max-w-4xl">{children}</main>
        </div>
    );
}

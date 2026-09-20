import { Link, router } from '@inertiajs/react';

export default function PortalLayout({ children }) {
    function handleLogout(e) {
        e.preventDefault();
        router.post('/portal/logout');
    }

    return (
        <div className="min-h-screen bg-paper text-ink">
            <header className="bg-ink text-paper px-6 py-4 flex items-center justify-between">
                <Link href="/portal" className="font-semibold">Client Hub</Link>
                <a href="/portal/logout" onClick={handleLogout} className="text-sm text-paper/60 hover:text-paper">
                    Sign out
                </a>
            </header>
            <main className="max-w-4xl mx-auto p-8">{children}</main>
        </div>
    );
}

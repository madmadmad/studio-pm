import { Link, router, usePage } from '@inertiajs/react';
import Avatar from '../Components/Avatar';

export default function PortalLayout({ children }) {
    const { props } = usePage();
    const contact = props.auth?.user;

    function handleLogout(e) {
        e.preventDefault();
        router.post('/portal/logout');
    }

    return (
        <div className="min-h-screen bg-porcelain text-gunmetal">
            <header className="bg-gunmetal text-porcelain px-6 py-4 flex items-center justify-between">
                <Link href="/portal" className="font-semibold">Client Hub</Link>
                <div className="flex items-center gap-4">
                    {contact && (
                        <Link href="/portal/profile" className="flex items-center gap-2 group">
                            <Avatar name={contact.name} avatarUrl={contact.avatar_url} id={contact.id} size={28} />
                            <span className="text-sm text-porcelain/80 group-hover:text-porcelain">{contact.name}</span>
                        </Link>
                    )}
                    <a href="/portal/logout" onClick={handleLogout} className="text-sm text-porcelain/60 hover:text-porcelain">
                        Sign out
                    </a>
                </div>
            </header>
            <main className="max-w-4xl mx-auto p-8">{children}</main>
        </div>
    );
}

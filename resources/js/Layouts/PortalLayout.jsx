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
        <div className="portal-shell">
            <header className="portal-shell__header">
                <Link href="/portal" className="portal-shell__brand">Client Hub</Link>
                <div className="portal-shell__account">
                    {contact && (
                        <Link href="/portal/profile" className="portal-shell__user">
                            <Avatar name={contact.name} avatarUrl={contact.avatar_url} id={contact.id} size={28} />
                            <span className="portal-shell__user-name">{contact.name}</span>
                        </Link>
                    )}
                    <a href="/portal/logout" onClick={handleLogout} className="portal-shell__logout">
                        Sign out
                    </a>
                </div>
            </header>
            <main className="portal-shell__main">{children}</main>
        </div>
    );
}

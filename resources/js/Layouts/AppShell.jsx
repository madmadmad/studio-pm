import { Link, router, usePage } from '@inertiajs/react';
import Avatar from '../Components/Avatar';

// The sidebar frame shared by the staff app (AppLayout) and the Client Hub
// (PortalLayout): brand, nav links, anything extra after them, then the
// signed-in person and sign-out at the bottom (`footerExtra` -- the alerts
// bell -- just above their rule). `navItems` are
// { href, label, exact?, also? }; a link is current when the URL starts
// with its href (or matches exactly, for `exact`), or starts with any of
// the `also` paths (a section's detail pages living elsewhere).
export default function AppShell({ navItems, extraNav, footerExtra, profileHref, logoutHref, logoutLabel = 'Log out', children }) {
    const { url, props } = usePage();
    const user = props.auth?.user;

    function isActive(item) {
        const path = url.split('?')[0];
        if (item.also?.some((prefix) => path.startsWith(prefix))) return true;
        return item.exact ? path === item.href : path.startsWith(item.href);
    }

    function handleLogout(e) {
        e.preventDefault();
        router.post(logoutHref);
    }

    return (
        <div className="app-shell">
            <aside className="app-shell__sidebar">
                <div className="app-shell__brand">
                    {/* The reversed lockup on the dark canvas, the dark one on the light (CSS picks). */}
                    <img src="/images/studio-lockup-rev.svg" alt="Madhouse Studio" className="app-shell__logo app-shell__logo--on-dark" />
                    <img src="/images/studio-lockup.svg" alt="" aria-hidden="true" className="app-shell__logo app-shell__logo--on-light" />
                </div>
                {navItems.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={`app-shell__nav-link${isActive(item) ? ' app-shell__nav-link--active' : ''}`}
                    >
                        {item.label}
                    </Link>
                ))}
                {extraNav}
                {footerExtra && <div className="app-shell__footer-extra">{footerExtra}</div>}
                <div className="app-shell__footer">
                    {user && (
                        <Link href={profileHref} className="app-shell__user">
                            <Avatar name={user.name} avatarUrl={user.avatar_url} id={user.id} size={28} />
                            <div className="app-shell__user-details">
                                <div className="app-shell__user-name">{user.name}</div>
                                <div className="app-shell__user-email">{user.email}</div>
                            </div>
                        </Link>
                    )}
                    <a href={logoutHref} onClick={handleLogout} className="app-shell__logout">
                        {logoutLabel}
                    </a>
                </div>
            </aside>

            <main className="app-shell__main">{children}</main>
        </div>
    );
}

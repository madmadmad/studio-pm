import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { List, X } from '@phosphor-icons/react';
import AccountMenu from '../Components/AccountMenu';

// Where the sidebar sits beside the page (abstracts/_breakpoints, lg).
const SIDEBAR_BESIDE = '(min-width: 64rem)';

// The phone and tablet menu: open or not, and closed again by a visit,
// Escape, or the window widening until the sidebar fits beside the page.
// The page behind doesn't scroll while it's open.
function useMenu() {
    const [open, setOpen] = useState(false);

    useEffect(() => router.on('navigate', () => setOpen(false)), []);

    useEffect(() => {
        if (!open) return undefined;

        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        const wide = window.matchMedia(SIDEBAR_BESIDE);
        const onWide = (e) => e.matches && setOpen(false);
        document.addEventListener('keydown', onKey);
        wide.addEventListener('change', onWide);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            wide.removeEventListener('change', onWide);
            document.body.style.overflow = overflow;
        };
    }, [open]);

    return [open, setOpen];
}

// The sidebar frame shared by the staff app (AppLayout) and the Client Hub
// (PortalLayout): brand, nav links, anything extra after them, then the
// signed-in person's menu -- Profile, Appearance, log out (AccountMenu) --
// at the bottom (`footerExtra` -- the alerts bell -- just above its rule).
// The sidebar stays in view as the page scrolls, so that menu is always at
// hand. `appearanceEndpoint` saves the person's Appearance (staff or client). `navItems` are
// { href, label, exact?, also? }; a link is current when the URL starts
// with its href (or matches exactly, for `exact`), or starts with any of
// the `also` paths (a section's detail pages living elsewhere), and `badge`
// is a node shown at its far end (Chat's unread count). `flush` drops the
// content panel's padding and holds it to the window's height, for a page
// that scrolls its own parts (Chat).
//
// Below lg a bar across the top stands in for the sidebar -- a menu button
// and the logo -- and the sidebar slides in over the page (_app-shell.scss).
export default function AppShell({ navItems, extraNav, footerExtra, profileHref, logoutHref, logoutLabel = 'Log out', appearanceEndpoint, flush = false, children }) {
    const { url, props } = usePage();
    const user = props.auth?.user;
    const branding = props.branding;
    const [menuOpen, setMenuOpen] = useMenu();

    function isActive(item) {
        const path = url.split('?')[0];
        if (item.also?.some((prefix) => path.startsWith(prefix))) return true;
        return item.exact ? path === item.href : path.startsWith(item.href);
    }

    // The reversed lockup on the dark canvas, the dark one on the light (CSS picks).
    const logo = (
        <>
            <img src={branding.logo_dark} alt={branding.name} className="app-shell__logo app-shell__logo--on-dark" />
            <img src={branding.logo} alt="" aria-hidden="true" className="app-shell__logo app-shell__logo--on-light" />
        </>
    );

    return (
        <div className={`app-shell${menuOpen ? ' app-shell--menu-open' : ''}`}>
            <header className="app-shell__topbar">
                <button
                    type="button"
                    onClick={() => setMenuOpen(true)}
                    aria-label="Menu"
                    aria-expanded={menuOpen}
                    aria-controls="app-shell-sidebar"
                    className="app-shell__menu-button"
                >
                    <List />
                </button>
                {/* The name, not the stacked lockup -- too tall for the bar. */}
                <Link href={navItems[0]?.href ?? '/'} className="app-shell__topbar-brand">{branding.name}</Link>
            </header>

            {menuOpen && <div className="app-shell__scrim" onClick={() => setMenuOpen(false)} aria-hidden="true" />}

            <aside id="app-shell-sidebar" className="app-shell__sidebar">
                <div className="app-shell__brand">
                    <div>{logo}</div>
                    <button type="button" onClick={() => setMenuOpen(false)} aria-label="Close menu" className="app-shell__menu-button app-shell__close">
                        <X />
                    </button>
                </div>
                {navItems.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={`app-shell__nav-link${isActive(item) ? ' app-shell__nav-link--active' : ''}`}
                    >
                        {item.label}
                        {item.badge}
                    </Link>
                ))}
                {extraNav}
                {footerExtra && <div className="app-shell__footer-extra">{footerExtra}</div>}
                <div className="app-shell__footer">
                    {user && (
                        <AccountMenu
                            user={user}
                            profileHref={profileHref}
                            logoutHref={logoutHref}
                            logoutLabel={logoutLabel}
                            appearanceEndpoint={appearanceEndpoint}
                        />
                    )}
                </div>
            </aside>

            {/* Out of reach (focus, screen readers) while the menu covers it. */}
            <main className={`app-shell__main${flush ? ' app-shell__main--flush' : ''}`} inert={menuOpen || undefined}>{children}</main>
        </div>
    );
}

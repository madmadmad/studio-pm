import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import AlertsMenu from '../Components/AlertsMenu';
import ChatNavBadge from '../Components/chat/ChatNavBadge';
import AppShell from './AppShell';
import { hasPermission, isAccountant } from '../lib/permissions';
import { startChat, useChat } from '../lib/chatStore';
import { registerServiceWorker, setBadge } from '../lib/push';
import { startSession } from '../lib/session';
import SignedOutNotice from '../Components/SignedOutNotice';

// `staffOnly`: never for the accountant, who has Bookkeeping, Invoices and
// Expenses alone (User::ACCOUNTANT_PERMISSIONS).
const NAV_ITEMS = [
    // The Overview (/overview) is off the sidebar for now; My Work is home.
    { href: '/my-work', label: 'My Work', staffOnly: true },
    { href: '/chat', label: 'Chat', badge: <ChatNavBadge />, staffOnly: true },
    { href: '/clients', label: 'Clients', permission: 'clients' },
    { href: '/projects', label: 'Projects', staffOnly: true },
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

// The staff app frame: the shared sidebar with the staff nav. Starts Chat's
// live connection (presence, unread counts) on the first page and keeps it
// across the rest. `flush` is AppShell's: a page that fills the window.
export default function AppLayout({ flush = false, children }) {
    const { props } = usePage();
    const user = props.auth?.user;

    const accountant = isAccountant(user);

    // Chat is the staff's; the accountant never connects to it. The service
    // worker (notifications on this device, public/sw.js) is the staff's too.
    // Everyone's session is kept alive while the app is open, and a sign-out
    // said plainly (lib/session.js).
    useEffect(() => {
        startSession();
        if (!accountant) {
            startChat(user, props.chatUnread);
            registerServiceWorker();
        }
    }, [user?.id]);

    // The installed app's icon badge: unread chat (live) plus unread client
    // threads (each page load) -- the same count a push sets (AppBadge).
    const chatUnread = useChat((s) => (s.started ? s.total.unread : null)) ?? props.chatUnread?.unread ?? 0;
    useEffect(() => {
        if (!accountant) setBadge(chatUnread + (props.clientUnread ?? 0));
    }, [chatUnread, props.clientUnread]);

    return (
        <AppShell
            navItems={NAV_ITEMS.filter((item) => (item.permission ? hasPermission(user, item.permission) : !(accountant && item.staffOnly)))}
            // Alerts are failed or skipped invoice sends.
            footerExtra={hasPermission(user, 'invoices') && <AlertsMenu />}
            // The accountant has no Profile page; their menu has Appearance and sign-out.
            profileHref={accountant ? null : '/profile'}
            logoutHref="/logout"
            appearanceEndpoint="/api/profile/appearance"
            flush={flush}
        >
            {children}
            <SignedOutNotice />
        </AppShell>
    );
}

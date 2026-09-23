import { useEffect, useState } from 'react';
import { Bell, BellRinging } from '@phosphor-icons/react';
import { api } from '../lib/api';
import { formatRelativeTime } from '../lib/format';

// The only way to notice a failed send or a skipped scheduled send for an
// invoice you aren't currently viewing -- backed by Laravel's own database
// notification channel, not a bespoke table. No real-time layer (matches
// this app's existing choice to skip live updates elsewhere): it refreshes
// on mount and whenever the dropdown is opened.
export default function NotificationBell() {
    const [open, setOpen] = useState(false);
    const [notifications, setNotifications] = useState([]);
    const [unreadCount, setUnreadCount] = useState(0);

    async function load() {
        try {
            const data = await api.get('/api/notifications');
            setNotifications(data.notifications);
            setUnreadCount(data.unread_count);
        } catch {
            // Silent -- a failed notification-count fetch shouldn't disrupt the rest of the app.
        }
    }

    useEffect(() => {
        load();
    }, []);

    async function toggle() {
        const next = !open;
        setOpen(next);
        if (next) await load();
    }

    async function markRead(notification) {
        if (notification.read_at) return;
        const updated = await api.post(`/api/notifications/${notification.id}/read`);
        setNotifications((current) => current.map((n) => (n.id === updated.id ? updated : n)));
        setUnreadCount((count) => Math.max(0, count - 1));
    }

    return (
        <div className="relative">
            <button
                type="button"
                onClick={toggle}
                aria-label={unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications'}
                className="relative icon-btn text-porcelain/60 hover:text-porcelain"
            >
                {unreadCount > 0 ? <BellRinging /> : <Bell />}
                {unreadCount > 0 && (
                    <span className="absolute -top-1 -right-1 min-w-[16px] h-4 px-1 rounded-full bg-watermelon text-white text-[10px] leading-4 text-center">
                        {unreadCount}
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute right-0 top-full mt-2 w-72 max-h-96 overflow-y-auto bg-white border border-border rounded shadow-lg z-50 text-gunmetal">
                    {notifications.length === 0 ? (
                        <p className="text-sm text-shadow-grey p-4">No notifications yet.</p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {notifications.map((n) => (
                                <li key={n.id}>
                                    <button
                                        type="button"
                                        onClick={() => markRead(n)}
                                        className={`w-full text-left px-3 py-2 text-xs hover:bg-porcelain ${n.read_at ? 'text-shadow-grey' : 'font-semibold'}`}
                                    >
                                        <div>{n.data.message}</div>
                                        <div className="text-shadow-grey font-normal mt-0.5">{formatRelativeTime(n.created_at)}</div>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

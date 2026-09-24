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
        <div className="notification-bell">
            <button
                type="button"
                onClick={toggle}
                aria-label={unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications'}
                className="notification-bell__trigger"
            >
                {unreadCount > 0 ? <BellRinging /> : <Bell />}
                {unreadCount > 0 && (
                    <span className="notification-bell__count">
                        {unreadCount}
                    </span>
                )}
            </button>

            {open && (
                <div className="popover notification-bell__panel">
                    {notifications.length === 0 ? (
                        <p className="notification-bell__empty">No notifications yet.</p>
                    ) : (
                        <ul>
                            {notifications.map((n) => (
                                <li key={n.id} className="notification-bell__entry">
                                    <button
                                        type="button"
                                        onClick={() => markRead(n)}
                                        className={`notification-bell__item${n.read_at ? ' notification-bell__item--read' : ''}`}
                                    >
                                        <div>{n.data.message}</div>
                                        <div className="notification-bell__time">{formatRelativeTime(n.created_at)}</div>
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

import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { formatRelativeTime } from '../lib/format';

// The only way to notice a failed send or a skipped scheduled send for an
// invoice you aren't currently viewing -- backed by Laravel's own database
// notification channel, not a bespoke table. No real-time layer (matches
// this app's existing choice to skip live updates elsewhere): it refreshes
// on mount and whenever the menu is opened.
//
// The trigger's look comes from the caller (`triggerClassName`, plus
// `activeTriggerClassName` while open), so it can match whatever
// navigation it sits in.
export default function AlertsMenu({ triggerClassName = '', activeTriggerClassName = '' }) {
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

    const triggerClasses = ['alerts-menu__trigger', triggerClassName, open && activeTriggerClassName].filter(Boolean).join(' ');

    return (
        <div className="alerts-menu">
            <button
                type="button"
                onClick={toggle}
                aria-expanded={open}
                aria-label={unreadCount > 0 ? `Alerts, ${unreadCount} unread` : 'Alerts'}
                className={triggerClasses}
            >
                Alerts
                {unreadCount > 0 && (
                    <span className="alerts-menu__count">
                        {unreadCount}
                    </span>
                )}
            </button>

            {open && (
                <div className="popover alerts-menu__panel">
                    {notifications.length === 0 ? (
                        <p className="alerts-menu__empty">No alerts yet.</p>
                    ) : (
                        <ul>
                            {notifications.map((n) => (
                                <li key={n.id} className="alerts-menu__entry">
                                    <button
                                        type="button"
                                        onClick={() => markRead(n)}
                                        className={`alerts-menu__item${n.read_at ? ' alerts-menu__item--read' : ''}`}
                                    >
                                        <div>{n.data.message}</div>
                                        <div className="alerts-menu__time">{formatRelativeTime(n.created_at)}</div>
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

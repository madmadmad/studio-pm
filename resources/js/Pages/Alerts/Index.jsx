import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import PageHeader from '../../Components/PageHeader';
import { api } from '../../lib/api';
import { formatRelativeTime } from '../../lib/format';

// Your alerts, newest first: a client opening an invoice or proposal, a
// failed or skipped invoice send, the reconciliation and sales-tax
// reminders. Unread ones stand out; opening one marks it read and, when
// it's about something, goes there. "Mark all read" clears the count in one
// go; the alerts stay listed, greyed.
export default function AlertsIndex({ notifications }) {
    const [clearing, setClearing] = useState(false);
    const hasUnread = notifications.some((n) => !n.read_at);

    async function markAllRead() {
        setClearing(true);
        try {
            await api.post('/api/notifications/read-all');
            router.reload({ only: ['notifications', 'alertsUnread'] });
        } finally {
            setClearing(false);
        }
    }

    async function open(notification) {
        if (!notification.read_at) {
            await api.post(`/api/notifications/${notification.id}/read`);
        }
        if (notification.data.url) {
            router.visit(notification.data.url);
        } else {
            router.reload({ only: ['notifications', 'alertsUnread'] });
        }
    }

    return (
        <AppLayout>
            <Head title="Alerts" />
            <PageHeader
                title="Alerts"
                actions={hasUnread && (
                    <Button variant="secondary" onClick={markAllRead} disabled={clearing}>
                        Mark all read
                    </Button>
                )}
            />

            <div className="card card--flush">
                {notifications.length === 0 ? (
                    <EmptyState text="No alerts yet." />
                ) : (
                    notifications.map((n) => (
                        <button
                            key={n.id}
                            type="button"
                            onClick={() => open(n)}
                            className={`list-row alerts__row${n.read_at ? ' alerts__row--read' : ''}`}
                        >
                            <span className="list-row__title alerts__message">{n.data.message}</span>
                            <span className="list-row__meta">{formatRelativeTime(n.created_at)}</span>
                        </button>
                    ))
                )}
            </div>
        </AppLayout>
    );
}

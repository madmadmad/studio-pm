import { usePage } from '@inertiajs/react';
import { useChat } from '../../lib/chatStore';
import ChatCount from './ChatCount';

// The Chat nav item's total: the page's own count (HandleInertiaRequests'
// chatUnread) until the live store has its first, then the store's.
export default function ChatNavBadge() {
    const initial = usePage().props.chatUnread;
    const live = useChat((s) => (s.started ? s.total : null));
    const total = live ?? initial ?? {};

    return <ChatCount unread={total.unread} mentions={total.mentions} className="app-shell__nav-badge" />;
}

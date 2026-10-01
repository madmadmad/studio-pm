// A project's unread message threads, as a red count (nothing when none).
export default function UnreadCount({ count }) {
    if (!(count > 0)) return null;
    const label = `${count} unread message thread${count === 1 ? '' : 's'}`;
    return <span className="count count--unread" title={label} aria-label={label}>{count}</span>;
}

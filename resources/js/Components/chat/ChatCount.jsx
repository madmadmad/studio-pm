// Chat's unread badge -- for the nav item and each conversation in the
// sidebar. Mentions of you outrank plain unreads: they show as a red "@3"
// (.count--mention); otherwise a quiet grey count of what's unread. Nothing
// when there's nothing new.
export default function ChatCount({ unread = 0, mentions = 0, className = '' }) {
    if (mentions > 0) {
        const label = `${mentions} mention${mentions === 1 ? '' : 's'} of you, ${unread} unread`;
        return <span className={`count count--mention ${className}`} title={label} aria-label={label}>@{mentions}</span>;
    }
    if (unread > 0) {
        const label = `${unread} unread message${unread === 1 ? '' : 's'}`;
        return <span className={`count ${className}`} title={label} aria-label={label}>{unread}</span>;
    }
    return null;
}

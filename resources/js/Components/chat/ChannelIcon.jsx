import { Hash } from '@phosphor-icons/react';

// A channel's mark: its emoji if it has one, else the #.
export default function ChannelIcon({ channel, className = '', size }) {
    if (channel.emoji) {
        return <span className={`channel-icon ${className}`.trim()} aria-hidden="true">{channel.emoji}</span>;
    }
    return <Hash className={className} size={size} aria-hidden="true" />;
}

import { Hash, MagnifyingGlass, Plus } from '@phosphor-icons/react';
import Avatar from '../Avatar';
import ChatCount from './ChatCount';
import PresenceDot from './PresenceDot';
import { conversationTitle } from './ChatConversation';

function Item({ conversation, me, active, counts, online, onOpen }) {
    const unread = counts?.unread ?? 0;
    const mentions = counts?.mentions ?? 0;
    const others = conversation.members.filter((m) => m.id !== me.id);
    const person = others[0] ?? me;

    const classes = ['chat-sidebar__item'];
    if (active) classes.push('chat-sidebar__item--active');
    if (unread > 0) classes.push('chat-sidebar__item--unread');

    return (
        <li>
            <a
                href={`/chat/${conversation.id}`}
                onClick={(e) => { e.preventDefault(); onOpen(conversation.id); }}
                aria-current={active ? 'page' : undefined}
                className={classes.join(' ')}
            >
                {conversation.type === 'channel' ? (
                    <Hash className="chat-sidebar__icon" />
                ) : others.length > 1 ? (
                    <span className="chat-sidebar__group" aria-hidden="true">{others.length}</span>
                ) : (
                    <PresenceDot online={online.has(person.id)}><Avatar name={person.name} avatarUrl={person.avatar_url} id={person.id} size={20} /></PresenceDot>
                )}
                <span className="chat-sidebar__name">{conversationTitle(conversation, me)}</span>
                <ChatCount unread={unread} mentions={mentions} />
            </a>
        </li>
    );
}

// Chat's own column: channels you're in, then your direct messages (most
// recent first), each with its unread badge.
export default function ChatSidebar({ conversations, activeId, me, counts, online, onOpen, onBrowseChannels, onNewMessage }) {
    const channels = conversations.filter((c) => c.type === 'channel').sort((a, b) => a.name.localeCompare(b.name));
    const directs = conversations.filter((c) => c.type === 'direct').sort((a, b) => (b.updated_at ?? '').localeCompare(a.updated_at ?? ''));

    const section = (label, items, action) => (
        <div className="chat-sidebar__section">
            <div className="chat-sidebar__heading">
                <h2 className="chat-sidebar__label">{label}</h2>
                {action}
            </div>
            <ul className="chat-sidebar__list">
                {items.map((c) => (
                    <Item key={c.id} conversation={c} me={me} active={c.id === activeId} counts={counts[c.id]} online={online} onOpen={onOpen} />
                ))}
            </ul>
        </div>
    );

    return (
        <nav className="chat-sidebar" aria-label="Conversations">
            {section('Channels', channels, (
                <button type="button" className="icon-btn icon-btn--secondary" title="Browse channels" aria-label="Browse channels" onClick={onBrowseChannels}>
                    <MagnifyingGlass />
                </button>
            ))}
            {channels.length === 0 && (
                <button type="button" className="link-btn chat-sidebar__empty" onClick={onBrowseChannels}>Find a channel to join</button>
            )}
            {section('Direct messages', directs, (
                <button type="button" className="icon-btn icon-btn--secondary" title="New message" aria-label="New message" onClick={onNewMessage}>
                    <Plus />
                </button>
            ))}
            {directs.length === 0 && (
                <button type="button" className="link-btn chat-sidebar__empty" onClick={onNewMessage}>Message a teammate</button>
            )}
        </nav>
    );
}

import { Fragment, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { ArrowDown, PencilSimple, SignOut, Users } from '@phosphor-icons/react';
import Avatar from '../Avatar';
import EmptyState from '../EmptyState';
import Lightbox from '../Lightbox';
import ChannelIcon from './ChannelIcon';
import ChatComposer from './ChatComposer';
import ChatMessage from './ChatMessage';
import PresenceDot from './PresenceDot';
import { api } from '../../lib/api';
import { setConversationCounts } from '../../lib/chatStore';
import { useChatConversation } from '../../lib/useChatConversation';
import { formatDaySeparator, isSameDay } from '../../lib/format';

const NEAR_BOTTOM_PX = 48;
const NEAR_TOP_PX = 240;
const GROUP_WITHIN_MS = 5 * 60 * 1000;

// A conversation's title as the sidebar and header show it: "#name" for a
// channel, the other people's names for a direct message.
export function conversationTitle(conversation, me) {
    if (conversation.type === 'channel') return conversation.name;
    const others = conversation.members.filter((m) => m.id !== me.id);
    return others.length ? others.map((m) => m.name).join(', ') : `${me.name} (you)`;
}

function typingLine(typing) {
    const names = Object.values(typing);
    if (names.length === 0) return '';
    if (names.length === 1) return `${names[0]} is typing…`;
    if (names.length === 2) return `${names[0]} and ${names[1]} are typing…`;
    return 'Several people are typing…';
}

// A follow-on from the same person moments after their last message, the
// same day: drawn without their avatar and name.
function continues(previous, message) {
    return previous && !previous.deleted && !previous.failed && !message.deleted
        && previous.user?.id === message.user?.id
        && isSameDay(previous.created_at, message.created_at)
        && new Date(message.created_at) - new Date(previous.created_at) < GROUP_WITHIN_MS;
}

function MembersMenu({ conversation, me, members, online, onEdit, onLeave }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        const close = (e) => !ref.current?.contains(e.target) && setOpen(false);
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('pointerdown', close);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', close);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const sorted = [...members].sort((a, b) => (online.has(b.id) - online.has(a.id)) || a.name.localeCompare(b.name));

    return (
        <div ref={ref} className="chat-header__members">
            <button type="button" className="chat-header__members-btn" onClick={() => setOpen((o) => !o)} aria-expanded={open} title="Who's here">
                <Users />
                <span className="u-tabular-nums">{members.length}</span>
            </button>
            {open && (
                <div className="popover chat-header__members-menu">
                    <ul className="chat-header__member-list">
                        {sorted.map((member) => (
                            <li key={member.id} className="chat-header__member">
                                <PresenceDot online={online.has(member.id)}><Avatar name={member.name} avatarUrl={member.avatar_url} id={member.id} size={24} /></PresenceDot>
                                <span className="chat-header__member-name">{member.id === me.id ? `${member.name} (you)` : member.name}</span>
                            </li>
                        ))}
                    </ul>
                    {conversation.type === 'channel' && (
                        <div className="chat-header__menu-actions">
                            <button type="button" className="text-action chat-header__menu-action" onClick={() => { setOpen(false); onEdit(); }}>
                                <PencilSimple /> Edit channel
                            </button>
                            <button type="button" className="text-action chat-header__menu-action" onClick={onLeave}>
                                <SignOut /> Leave #{conversation.name}
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// The open conversation: its header, its history (older pages load as you
// scroll up), who's typing, and the composer. Marks itself read while it's
// on screen and scrolled to the newest message.
export default function ChatConversation({ conversation, me, staff, staffById, online, limits, onEdit, onLeave }) {
    const chat = useChatConversation(conversation.id, me);
    const { messages, loading, hasMore, loadingOlder, lastReadId, typing } = chat;
    const list = useRef(null);
    const atBottom = useRef(true);
    const [showJump, setShowJump] = useState(false);
    const [lightbox, setLightbox] = useState(null);
    const [dropped, setDropped] = useState(null);
    const [dragging, setDragging] = useState(false);
    const reportedRead = useRef(0);
    const firstScrollDone = useRef(false);
    const prependAnchor = useRef(null);
    // Where "New" goes: fixed when the conversation opens, so it doesn't
    // jump as you read.
    const [newFrom, setNewFrom] = useState(undefined);

    // Who you can mention: the conversation's people (for a channel, its
    // members as the server sees them -- everyone in the studio, filtered to
    // members, comes from `staff`).
    const members = useMemo(() => {
        const ids = new Set(conversation.member_ids ?? conversation.members.map((m) => m.id));
        return staff.filter((s) => ids.has(s.id) && s.id !== me.id);
    }, [conversation, staff, me.id]);
    const allMembers = useMemo(() => {
        const ids = new Set(conversation.member_ids ?? conversation.members.map((m) => m.id));
        return staff.filter((s) => ids.has(s.id));
    }, [conversation, staff]);

    const newest = [...messages].reverse().find((m) => !m.pending);

    useEffect(() => {
        if (newFrom === undefined && !loading) setNewFrom(lastReadId ?? 0);
    }, [loading, lastReadId, newFrom]);

    const scrollToBottom = useCallback((behavior = 'auto') => {
        const el = list.current;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior });
    }, []);

    // First draw: to the "New" line if there's unread history, else the end.
    // Then: keep to the end as messages arrive, if that's where you were;
    // keep your place when an older page is put in above.
    useLayoutEffect(() => {
        const el = list.current;
        // Not until the "New" line's place is known, or putting it in
        // afterwards would push the end out of view.
        if (!el || loading || newFrom === undefined) return;

        if (prependAnchor.current) {
            el.scrollTop += el.scrollHeight - prependAnchor.current;
            prependAnchor.current = null;
            return;
        }
        if (!firstScrollDone.current) {
            firstScrollDone.current = true;
            // Only when the unread run is taller than the view; otherwise
            // the end shows it all (and counts as read).
            const divider = el.querySelector('.chat-history__new');
            if (divider && el.scrollHeight - divider.offsetTop > el.clientHeight) {
                el.scrollTop = divider.offsetTop - el.clientHeight / 3;
                atBottom.current = false;
            } else {
                scrollToBottom();
                atBottom.current = true;
            }
            return;
        }
        const last = messages[messages.length - 1];
        if (atBottom.current || (last?.pending && last.user?.id === me.id)) scrollToBottom();
        else if (last && last.user?.id !== me.id) setShowJump(true);
    }, [messages, loading, newFrom, scrollToBottom, me.id]);

    // An image finishing loading grows the list: stay at the end.
    const onImageLoad = useCallback(() => {
        if (atBottom.current) scrollToBottom();
    }, [scrollToBottom]);

    const markRead = useCallback(() => {
        if (!newest || document.hidden || !atBottom.current || newest.id <= reportedRead.current) return;
        reportedRead.current = newest.id;
        api.post(`/api/chat/conversations/${conversation.id}/read`, { message_id: newest.id })
            .then((counts) => setConversationCounts(conversation.id, counts))
            .catch(() => { reportedRead.current = 0; });
    }, [newest?.id, conversation.id]);

    useEffect(() => {
        const timer = setTimeout(markRead, 300);
        return () => clearTimeout(timer);
    }, [markRead]);

    useEffect(() => {
        const onVisible = () => !document.hidden && markRead();
        document.addEventListener('visibilitychange', onVisible);
        return () => document.removeEventListener('visibilitychange', onVisible);
    }, [markRead]);

    async function onScroll() {
        const el = list.current;
        atBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < NEAR_BOTTOM_PX;
        if (atBottom.current) {
            setShowJump(false);
            markRead();
        }
        if (el.scrollTop < NEAR_TOP_PX && hasMore && !loadingOlder) {
            const before = el.scrollHeight;
            prependAnchor.current = before;
            const loaded = await chat.loadOlder().catch(() => false);
            if (!loaded) prependAnchor.current = null;
        }
    }

    async function react(message, emoji) {
        chat.replace(await api.post(`/api/chat/messages/${message.id}/reactions`, { emoji }));
    }

    async function edit(message, body) {
        chat.replace(await api.patch(`/api/chat/messages/${message.id}`, { body }));
    }

    async function remove(message) {
        if (!window.confirm('Delete this message? Everyone will see that it was deleted.')) return;
        chat.replace(await api.delete(`/api/chat/messages/${message.id}`));
    }

    function onDrop(e) {
        e.preventDefault();
        setDragging(false);
        if (e.dataTransfer.files?.length) setDropped(Array.from(e.dataTransfer.files));
    }

    const title = conversationTitle(conversation, me);
    const others = conversation.type === 'direct' ? conversation.members.filter((m) => m.id !== me.id) : [];
    const typingText = typingLine(typing);

    return (
        <section
            className={`chat-pane${dragging ? ' chat-pane--dragging' : ''}`}
            aria-label={title}
            onDragOver={(e) => { if (e.dataTransfer.types.includes('Files')) { e.preventDefault(); setDragging(true); } }}
            onDragLeave={(e) => { if (!e.currentTarget.contains(e.relatedTarget)) setDragging(false); }}
            onDrop={onDrop}
        >
            <header className="chat-header">
                <div className="chat-header__title-row">
                    {conversation.type === 'channel' ? (
                        <h1 className="chat-header__title"><ChannelIcon channel={conversation} className="chat-header__hash" />{title}</h1>
                    ) : (
                        <h1 className="chat-header__title">
                            {others.length === 1 && <PresenceDot online={online.has(others[0].id)} />}
                            {title}
                        </h1>
                    )}
                    <MembersMenu conversation={conversation} me={me} members={allMembers} online={online} onEdit={onEdit} onLeave={onLeave} />
                </div>
                {conversation.description && <p className="chat-header__description">{conversation.description}</p>}
            </header>

            <div ref={list} className="chat-history" onScroll={onScroll} role="log" aria-live="polite" aria-relevant="additions">
                {loading ? (
                    <p className="chat-history__status">Loading…</p>
                ) : chat.error ? (
                    <p className="chat-history__status" role="alert">{chat.error}</p>
                ) : (
                    <>
                        {loadingOlder && <p className="chat-history__status">Loading earlier messages…</p>}
                        {!hasMore && (
                            <div className="chat-history__start">
                                {messages.length === 0
                                    ? <EmptyState text={conversation.type === 'channel' ? `This is the start of #${conversation.name}. Say hello.` : 'This is the start of your conversation. Say hello.'} />
                                    : <p className="chat-history__status">The start of {conversation.type === 'channel' ? `#${conversation.name}` : 'your conversation'}.</p>}
                            </div>
                        )}
                        {messages.map((message, i) => {
                            const previous = messages[i - 1];
                            const newDay = !previous || !isSameDay(previous.created_at, message.created_at);
                            const isFirstNew = newFrom > 0 && !message.pending && message.id > newFrom && message.user?.id !== me.id
                                && !messages.slice(0, i).some((m) => !m.pending && m.id > newFrom && m.user?.id !== me.id);
                            return (
                                <Fragment key={message.client_id && message.pending ? `pending-${message.client_id}` : message.id}>
                                    {newDay && <div className="chat-history__day" role="separator"><span>{formatDaySeparator(message.created_at)}</span></div>}
                                    {isFirstNew && <div className="chat-history__new" role="separator"><span>New</span></div>}
                                    <ChatMessage
                                        message={message}
                                        continued={!newDay && !isFirstNew && continues(previous, message)}
                                        me={me}
                                        staffById={staffById}
                                        members={members}
                                        limits={limits}
                                        onReact={react}
                                        onEdit={edit}
                                        onDelete={remove}
                                        onRetry={chat.retry}
                                        onDiscard={chat.discard}
                                        onOpenImages={(images, index) => setLightbox({ images, index })}
                                        onImageLoad={onImageLoad}
                                    />
                                </Fragment>
                            );
                        })}
                    </>
                )}
            </div>

            <div className="chat-pane__footer">
                {showJump && (
                    <button type="button" className="chat-pane__jump" onClick={() => { scrollToBottom('smooth'); setShowJump(false); }}>
                        <ArrowDown size={14} /> New messages
                    </button>
                )}
                <p className="chat-pane__typing" aria-live="polite">{typingText}</p>
                <ChatComposer
                    placeholder={conversation.type === 'channel' ? `Message #${conversation.name}` : `Message ${title}`}
                    members={members}
                    limits={limits}
                    onSend={chat.send}
                    onTyping={chat.notifyTyping}
                    droppedFiles={dropped}
                    focusKey={conversation.id}
                />
            </div>

            {lightbox && (
                <Lightbox
                    images={lightbox.images}
                    index={lightbox.index}
                    onClose={() => setLightbox(null)}
                    onNavigate={(index) => setLightbox((l) => ({ ...l, index }))}
                />
            )}
        </section>
    );
}

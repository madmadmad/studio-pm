import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import ChatSidebar from '../../Components/chat/ChatSidebar';
import ChatConversation, { conversationTitle } from '../../Components/chat/ChatConversation';
import { BrowseChannelsDialog, EditChannelDialog, NewMessageDialog } from '../../Components/chat/ChatDialogs';
import EmptyState from '../../Components/EmptyState';
import { api } from '../../lib/api';
import { onActivity, useChat } from '../../lib/chatStore';

// Chat: the studio's own channels and direct messages, staff only. The
// conversation list comes with the page; switching conversations swaps the
// URL without reloading it. Everything live -- messages, typing, who's
// online, unread counts -- comes over the socket (lib/chatStore,
// lib/useChatConversation), with the API as the record behind it.
export default function ChatIndex({ conversations: initial, conversationId, staff, limits }) {
    const me = usePage().props.auth.user;
    const [conversations, setConversations] = useState(initial);
    const [activeId, setActiveId] = useState(conversationId);
    const [dialog, setDialog] = useState(null); // 'channels' | 'direct' | 'edit'
    const online = useChat((s) => s.online);
    const started = useChat((s) => s.started);
    const liveCounts = useChat((s) => s.counts);
    const staffById = useMemo(() => new Map(staff.map((s) => [s.id, s])), [staff]);

    // Until the live counts arrive, the page's own.
    const counts = useMemo(() => (started ? liveCounts : Object.fromEntries(initial.map((c) => [c.id, { unread: c.unread, mentions: c.mentions }]))), [started, liveCounts, initial]);

    const reload = useCallback(() => {
        api.get('/api/chat/conversations').then(setConversations).catch(() => {});
    }, []);

    // A conversation's changed somewhere: a new direct message with you, a
    // message moving a conversation up the list.
    useEffect(() => {
        let timer;
        const off = onActivity(() => {
            clearTimeout(timer);
            timer = setTimeout(reload, 300);
        });
        return () => { off(); clearTimeout(timer); };
    }, [reload]);

    useEffect(() => setActiveId(conversationId), [conversationId]);

    function open(id) {
        if (id === activeId) return;
        setActiveId(id);
        router.visit(`/chat/${id}`, { preserveState: true, preserveScroll: true, only: ['conversationId'] });
    }

    // From a dialog: put the conversation in the list (if new) and open it.
    function opened(id, summary) {
        setDialog(null);
        if (summary) setConversations((list) => [...list.filter((c) => c.id !== id), summary]);
        else reload();
        open(id);
    }

    async function leave(conversation) {
        if (!window.confirm(`Leave #${conversation.name}? You can join again from Channels.`)) return;
        await api.post(`/api/chat/channels/${conversation.id}/leave`);
        setConversations((list) => list.filter((c) => c.id !== conversation.id));
        setActiveId(null);
        router.visit('/chat', { preserveState: true, only: ['conversationId'] });
    }

    // Out of the sidebar until something new arrives in it.
    async function close(conversation) {
        await api.post(`/api/chat/direct/${conversation.id}/close`);
        setConversations((list) => list.filter((c) => c.id !== conversation.id));
        if (conversation.id === activeId) {
            setActiveId(null);
            router.visit('/chat', { preserveState: true, only: ['conversationId'] });
        }
    }

    const active = conversations.find((c) => c.id === activeId);

    // Opening /chat on its own lands in #general (or your first conversation).
    useEffect(() => {
        if (!activeId && conversations.length) {
            const first = conversations.find((c) => c.slug === 'general') ?? conversations[0];
            open(first.id);
        }
    }, [activeId, conversations.length]);

    return (
        <AppLayout flush>
            <Head title={active ? `${active.type === 'channel' ? '#' : ''}${conversationTitle(active, me)} · Chat` : 'Chat'} />
            <div className="chat">
                <ChatSidebar
                    conversations={conversations}
                    activeId={activeId}
                    me={me}
                    counts={counts}
                    online={online}
                    onOpen={open}
                    onClose={close}
                    onBrowseChannels={() => setDialog('channels')}
                    onNewMessage={() => setDialog('direct')}
                />
                {active ? (
                    <ChatConversation
                        key={active.id}
                        conversation={active}
                        me={me}
                        staff={staff}
                        staffById={staffById}
                        online={online}
                        limits={limits}
                        onEdit={() => setDialog('edit')}
                        onLeave={() => leave(active)}
                    />
                ) : (
                    <div className="chat-pane chat-pane--empty">
                        <EmptyState text="Join a channel or message a teammate to get started." />
                    </div>
                )}
            </div>

            {dialog === 'channels' && <BrowseChannelsDialog onClose={() => setDialog(null)} onOpened={opened} />}
            {dialog === 'edit' && active && (
                <EditChannelDialog
                    channel={active}
                    onClose={() => setDialog(null)}
                    onSaved={(summary) => {
                        setConversations((list) => list.map((c) => (c.id === summary.id ? summary : c)));
                        setDialog(null);
                    }}
                />
            )}
            {dialog === 'direct' && <NewMessageDialog staff={staff} me={me} online={online} onClose={() => setDialog(null)} onOpened={opened} />}
        </AppLayout>
    );
}

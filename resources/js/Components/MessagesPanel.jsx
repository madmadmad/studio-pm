import { useState } from 'react';
import { formatDate } from '../lib/format';
import { api } from '../lib/api';
import Badge from './Badge';
import Button from './Button';
import EmptyState from './EmptyState';

function participantName(participant) {
    return participant.user?.name ?? participant.contact?.name ?? 'Unknown';
}

function isSameActor(a, currentActorType, currentActorId) {
    return a.type === currentActorType && String(a.id) === String(currentActorId);
}

function threadParticipantActors(thread) {
    return (thread.participants || []).map((p) => ({
        type: p.user ? 'user' : 'contact',
        id: p.user ? p.user.id : p.contact?.id,
        name: participantName(p),
    }));
}

function senderName(message) {
    return message.sender_user?.name ?? message.sender_contact?.name ?? 'Unknown';
}

function NewThreadForm({ recipientOptions, onCreate, onCancel }) {
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [selected, setSelected] = useState([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function toggle(token) {
        setSelected((current) => (current.includes(token) ? current.filter((t) => t !== token) : [...current, token]));
    }

    async function submit(e) {
        e.preventDefault();
        if (!subject.trim() || !body.trim() || selected.length === 0) {
            setError('Add a subject, a message, and at least one recipient.');
            return;
        }
        setSaving(true);
        setError('');
        try {
            await onCreate({ subject, body, recipients: selected });
        } catch (err) {
            setError(err.message || 'Could not send this message.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={submit} className="card card-padded mb-4">
            <div className="text-xs font-semibold text-shadow-grey mb-2 uppercase">To</div>
            <div className="flex flex-wrap gap-2 mb-3">
                {recipientOptions.length === 0 && <div className="text-sm text-shadow-grey">No one else is on this project yet.</div>}
                {recipientOptions.map((option) => (
                    <label
                        key={option.token}
                        className={`inline-flex items-center gap-2 text-sm border rounded-full px-3 py-1 cursor-pointer ${
                            selected.includes(option.token) ? 'border-fern bg-fern-soft text-fern' : 'border-border text-shadow-grey'
                        }`}
                    >
                        <input
                            type="checkbox"
                            className="hidden"
                            checked={selected.includes(option.token)}
                            onChange={() => toggle(option.token)}
                        />
                        {option.name}
                        <span className="text-xs opacity-70">{option.sublabel}</span>
                    </label>
                ))}
            </div>
            <input
                placeholder="Subject"
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
                className="field mb-2"
            />
            <textarea
                placeholder="Message…"
                value={body}
                onChange={(e) => setBody(e.target.value)}
                rows={4}
                className="field mb-2"
            />
            {error && <div className="text-sm text-watermelon mb-2">{error}</div>}
            <div className="flex justify-end gap-2">
                <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
                <Button type="submit" disabled={saving}>Send</Button>
            </div>
        </form>
    );
}

function ThreadView({ thread, currentActorType, currentActorId, endpoints, onChange, onBack }) {
    const [body, setBody] = useState('');
    const [saving, setSaving] = useState(false);
    const [joining, setJoining] = useState(false);

    const participants = threadParticipantActors(thread);
    const amParticipant = participants.some((p) => isSameActor(p, currentActorType, currentActorId));

    async function submitReply(e) {
        e.preventDefault();
        if (!body.trim()) return;
        setSaving(true);
        try {
            await api.post(endpoints.reply(thread.id), { body });
            setBody('');
            onChange();
        } finally {
            setSaving(false);
        }
    }

    async function join() {
        setJoining(true);
        try {
            await api.post(endpoints.join(thread.id));
            onChange();
        } finally {
            setJoining(false);
        }
    }

    return (
        <div>
            <button onClick={onBack} className="text-sm text-shadow-grey hover:text-gunmetal mb-3">&larr; All messages</button>

            <div className="card overflow-hidden mb-4">
                <div className="px-4 py-3 border-b border-border">
                    <div className="text-sm font-semibold">{thread.subject}</div>
                    <div className="text-xs text-shadow-grey mt-1">
                        With: {participants.map((p) => p.name).join(', ') || '—'}
                    </div>
                </div>
                <div className="divide-y divide-border">
                    {[thread, ...(thread.replies || [])].map((message) => (
                        <div key={message.id} className="px-4 py-3">
                            <div className="flex items-center justify-between mb-1">
                                <span className="text-sm font-medium">{senderName(message)}</span>
                                <span className="text-xs text-shadow-grey">{formatDate(message.sent_at)}</span>
                            </div>
                            <div className="text-sm whitespace-pre-wrap">{message.body}</div>
                        </div>
                    ))}
                </div>
            </div>

            {amParticipant ? (
                <form onSubmit={submitReply} className="card card-padded">
                    <textarea
                        placeholder="Write a reply…"
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        rows={3}
                        className="field mb-2"
                    />
                    <div className="flex justify-end">
                        <Button type="submit" disabled={saving}>Reply</Button>
                    </div>
                </form>
            ) : (
                <div className="card card-padded flex items-center justify-between">
                    <div className="text-sm text-shadow-grey">You're not part of this thread yet.</div>
                    <Button variant="confirm" onClick={join} disabled={joining}>Join thread</Button>
                </div>
            )}
        </div>
    );
}

export default function MessagesPanel({ project, currentActorType, currentActorId, recipientOptions, endpoints, onChange }) {
    const [showForm, setShowForm] = useState(false);
    const [openThreadId, setOpenThreadId] = useState(null);

    const threads = project.messages || [];
    const openThread = threads.find((t) => t.id === openThreadId);

    async function createThread(payload) {
        await api.post(endpoints.create, payload);
        setShowForm(false);
        onChange();
    }

    if (openThread) {
        return (
            <ThreadView
                thread={openThread}
                currentActorType={currentActorType}
                currentActorId={currentActorId}
                endpoints={endpoints}
                onChange={onChange}
                onBack={() => setOpenThreadId(null)}
            />
        );
    }

    return (
        <div>
            <div className="flex justify-end mb-4">
                {!showForm && (
                    <Button onClick={() => setShowForm(true)}>New message</Button>
                )}
            </div>

            {showForm && (
                <NewThreadForm recipientOptions={recipientOptions} onCreate={createThread} onCancel={() => setShowForm(false)} />
            )}

            {threads.length === 0 ? (
                <EmptyState text="No messages yet." />
            ) : (
                <div className="card overflow-hidden">
                    {threads.map((thread) => {
                        const participants = threadParticipantActors(thread);
                        const amParticipant = participants.some((p) => isSameActor(p, currentActorType, currentActorId));
                        const lastActivity = thread.replies?.length ? thread.replies[thread.replies.length - 1].sent_at : thread.sent_at;

                        return (
                            <button
                                key={thread.id}
                                onClick={() => setOpenThreadId(thread.id)}
                                className="w-full text-left px-4 py-3 border-b border-border last:border-b-0 hover:bg-porcelain flex items-center justify-between"
                            >
                                <div>
                                    <div className="text-sm font-medium flex items-center gap-2">
                                        {thread.subject}
                                        {!amParticipant && <Badge tone="watermelon" label="Not joined" />}
                                    </div>
                                    <div className="text-xs text-shadow-grey mt-1">{participants.map((p) => p.name).join(', ') || '—'}</div>
                                </div>
                                <div className="text-xs text-shadow-grey">{formatDate(lastActivity)}</div>
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

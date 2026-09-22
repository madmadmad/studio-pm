import { useEffect, useRef, useState } from 'react';
import { Paperclip, PencilSimple, Trash, X } from '@phosphor-icons/react';
import { formatDate, formatDateTime, formatDaySeparator, formatRelativeTime, isSameDay } from '../lib/format';
import { api } from '../lib/api';
import Badge from './Badge';
import Button from './Button';
import EmptyState from './EmptyState';
import Avatar from './Avatar';
import AttachmentChip from './AttachmentChip';
import Lightbox from './Lightbox';

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

// text content only, never raw HTML -- React escapes every plain string
// node it renders, so turning a URL into an <a> here can't introduce a
// script injection the way dangerouslySetInnerHTML could.
function linkify(text) {
    return text.split(/(https?:\/\/[^\s]+)/g).map((part, i) =>
        /^https?:\/\//.test(part)
            ? <a key={i} href={part} target="_blank" rel="noopener noreferrer" className="underline break-all">{part}</a>
            : <span key={i}>{part}</span>
    );
}

function canModifyMessage(message, currentActorType, currentActorId, currentActorRole) {
    if (message.deleted_at) return false;
    if (currentActorRole === 'manager') return true;

    const isClientAuthor = !!message.sender_contact;
    if (isClientAuthor !== (currentActorType === 'contact')) return false;

    const senderId = message.sender_user?.id ?? message.sender_contact?.id;
    return String(senderId) === String(currentActorId);
}

// Shared by the new-thread form and the reply box -- tracks files chosen
// via the paperclip button, drag-and-drop, or a clipboard paste, before
// they're actually sent.
function usePendingAttachments() {
    const [files, setFiles] = useState([]);

    function addFiles(fileList) {
        const additions = Array.from(fileList).map((file) => ({
            key: `${file.name}-${file.size}-${Math.random().toString(36).slice(2)}`,
            file,
            previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
        }));
        setFiles((current) => [...current, ...additions]);
    }

    function removeFile(key) {
        setFiles((current) => current.filter((f) => f.key !== key));
    }

    return { files, addFiles, removeFile, clear: () => setFiles([]) };
}

function PendingAttachments({ files, onRemove }) {
    if (files.length === 0) return null;

    return (
        <div className="flex flex-wrap gap-2 mb-2">
            {files.map(({ key, file, previewUrl }) => (
                <div key={key} className="relative">
                    {previewUrl ? (
                        <img src={previewUrl} alt={file.name} className="w-16 h-16 object-cover rounded-lg border border-border" />
                    ) : (
                        <div className="w-16 h-16 flex items-center justify-center text-xs text-center px-1 text-shadow-grey rounded-lg border border-border bg-white truncate">
                            {file.name}
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={() => onRemove(key)}
                        className="absolute -top-1.5 -right-1.5 bg-gunmetal text-white rounded-full w-5 h-5 flex items-center justify-center"
                    >
                        <X size={12} weight="bold" />
                    </button>
                </div>
            ))}
        </div>
    );
}

function AutoGrowTextarea({ value, onChange, onKeyDown, onPaste, placeholder, autoFocus }) {
    const ref = useRef(null);

    useEffect(() => {
        if (!ref.current) return;
        ref.current.style.height = 'auto';
        ref.current.style.height = `${ref.current.scrollHeight}px`;
    }, [value]);

    return (
        <textarea
            ref={ref}
            autoFocus={autoFocus}
            value={value}
            onChange={onChange}
            onKeyDown={onKeyDown}
            onPaste={onPaste}
            placeholder={placeholder}
            rows={1}
            className="field mb-2 resize-none max-h-64 overflow-y-auto"
        />
    );
}

function NewThreadForm({ recipientOptions, endpoints, onCreate, onCancel }) {
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [selected, setSelected] = useState([]);
    const [saving, setSaving] = useState(false);
    const [progress, setProgress] = useState(0);
    const [error, setError] = useState('');
    const attachments = usePendingAttachments();

    function toggle(token) {
        setSelected((current) => (current.includes(token) ? current.filter((t) => t !== token) : [...current, token]));
    }

    function onKeyDown(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
            e.preventDefault();
            submit(e);
        }
    }

    function onPaste(e) {
        const files = Array.from(e.clipboardData?.items || [])
            .filter((item) => item.kind === 'file')
            .map((item) => item.getAsFile())
            .filter(Boolean);
        if (files.length) attachments.addFiles(files);
    }

    async function submit(e) {
        e.preventDefault();
        if (!subject.trim() || (!body.trim() && attachments.files.length === 0) || selected.length === 0) {
            setError('Add a subject, a recipient, and a message or attachment.');
            return;
        }
        setSaving(true);
        setError('');
        setProgress(0);
        try {
            const form = new FormData();
            form.append('subject', subject);
            form.append('body', body);
            selected.forEach((token) => form.append('recipients[]', token));
            attachments.files.forEach(({ file }) => form.append('attachments[]', file));

            await api.postFormWithProgress(endpoints.create, form, setProgress);
            onCreate();
        } catch (err) {
            setError(err.message || 'Could not send this message.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <form
            onSubmit={submit}
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => { e.preventDefault(); if (e.dataTransfer.files?.length) attachments.addFiles(e.dataTransfer.files); }}
            className="card card-padded mb-4"
        >
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
            <AutoGrowTextarea
                value={body}
                onChange={(e) => setBody(e.target.value)}
                onKeyDown={onKeyDown}
                onPaste={onPaste}
                placeholder="Message… (⌘/Ctrl+Enter to send)"
            />
            <PendingAttachments files={attachments.files} onRemove={attachments.removeFile} />
            {error && <div className="text-sm text-watermelon mb-2">{error}</div>}
            {saving && attachments.files.length > 0 && (
                <div className="h-1 bg-border rounded mb-2 overflow-hidden">
                    <div className="h-full bg-fern transition-all" style={{ width: `${progress}%` }} />
                </div>
            )}
            <div className="flex items-center justify-between">
                <label className="icon-btn icon-btn-secondary cursor-pointer" title="Attach files">
                    <Paperclip size={20} />
                    <input type="file" multiple className="hidden" onChange={(e) => { attachments.addFiles(e.target.files); e.target.value = ''; }} />
                </label>
                <div className="flex gap-2">
                    <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
                    <Button type="submit" disabled={saving}>Send</Button>
                </div>
            </div>
        </form>
    );
}

function MessageAttachments({ message, endpoints, onOpenLightbox }) {
    const images = (message.attachments || []).filter((a) => a.is_image);
    const files = (message.attachments || []).filter((a) => !a.is_image);
    const lightboxImages = images.map((img) => ({
        src: endpoints.attachmentUrl(img.id),
        downloadUrl: endpoints.attachmentUrl(img.id),
        name: img.original_name,
    }));

    return (
        <>
            {images.length === 1 && (
                <button
                    type="button"
                    onClick={() => onOpenLightbox(lightboxImages, 0)}
                    className={`block rounded-lg overflow-hidden ${message.body ? 'mt-3' : ''}`}
                >
                    <img
                        src={images[0].thumbnail_status === 'ready' ? endpoints.attachmentThumbnailUrl(images[0].id) : endpoints.attachmentUrl(images[0].id)}
                        alt={images[0].original_name}
                        className="max-w-[640px] max-h-[480px] w-auto h-auto"
                    />
                </button>
            )}
            {images.length > 1 && (
                <div className={`grid grid-cols-2 gap-2 max-w-[640px] ${message.body ? 'mt-3' : ''}`}>
                    {images.map((img, i) => (
                        <button
                            key={img.id}
                            type="button"
                            onClick={() => onOpenLightbox(lightboxImages, i)}
                            className="block rounded-lg overflow-hidden aspect-square"
                        >
                            <img
                                src={img.thumbnail_status === 'ready' ? endpoints.attachmentThumbnailUrl(img.id) : endpoints.attachmentUrl(img.id)}
                                alt={img.original_name}
                                className="w-full h-full object-cover"
                            />
                        </button>
                    ))}
                </div>
            )}
            {files.length > 0 && (
                <div className={`flex flex-col gap-2 ${message.body || images.length > 0 ? 'mt-3' : ''}`}>
                    {files.map((file) => (
                        <AttachmentChip key={file.id} attachment={file} downloadUrl={endpoints.attachmentUrl(file.id)} />
                    ))}
                </div>
            )}
        </>
    );
}

function MessageRow({ message, isLast, endpoints, currentActorType, currentActorId, currentActorRole, onChange, onOpenLightbox }) {
    const [editing, setEditing] = useState(false);
    const [editBody, setEditBody] = useState(message.body || '');
    const [saving, setSaving] = useState(false);

    const sender = message.sender_user || message.sender_contact;
    const isClientAuthor = !!message.sender_contact;
    const isDeleted = !!message.deleted_at;
    const canModify = canModifyMessage(message, currentActorType, currentActorId, currentActorRole);

    async function saveEdit() {
        if (!editBody.trim()) return;
        setSaving(true);
        try {
            await api.patch(endpoints.update(message.id), { body: editBody });
            setEditing(false);
            onChange();
        } finally {
            setSaving(false);
        }
    }

    async function remove() {
        if (!confirm('Delete this message? This can\'t be undone.')) return;
        await api.delete(endpoints.destroy(message.id));
        onChange();
    }

    return (
        <div className="flex gap-3">
            <div className="relative flex-shrink-0 flex flex-col items-center self-stretch">
                <Avatar name={sender?.name} avatarUrl={sender?.avatar_url} id={sender?.id ?? sender?.name} className="!w-8 !h-8 sm:!w-10 sm:!h-10" />
                {!isLast && <div className="message-connector flex-1 mt-1" />}
            </div>
            <div className="flex-1 min-w-0 pb-4">
                <div className="flex items-center gap-2 mb-1 flex-wrap">
                    <span className="text-sm font-semibold">{sender?.name || 'Unknown'}</span>
                    {isClientAuthor && <Badge tone="watermelon" label="Client" />}
                    <span className="text-xs text-shadow-grey" title={formatDateTime(message.sent_at)}>
                        {formatRelativeTime(message.sent_at)}
                    </span>
                    {canModify && !editing && (
                        <span className="ml-auto flex items-center gap-2">
                            <button onClick={() => { setEditBody(message.body || ''); setEditing(true); }} className="icon-btn icon-btn-confirm" title="Edit">
                                <PencilSimple size={16} />
                            </button>
                            <button onClick={remove} className="icon-btn icon-btn-danger" title="Delete">
                                <Trash size={16} />
                            </button>
                        </span>
                    )}
                </div>

                {isDeleted ? (
                    <div className="message-card text-shadow-grey italic text-sm">Message deleted</div>
                ) : editing ? (
                    <div className="message-card">
                        <textarea
                            value={editBody}
                            onChange={(e) => setEditBody(e.target.value)}
                            rows={3}
                            className="field mb-2"
                            autoFocus
                        />
                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setEditing(false)}>Cancel</Button>
                            <Button onClick={saveEdit} disabled={saving}>Save</Button>
                        </div>
                    </div>
                ) : (
                    <div className="message-card">
                        {message.body && <div className="text-sm whitespace-pre-wrap break-words">{linkify(message.body)}</div>}
                        <MessageAttachments message={message} endpoints={endpoints} onOpenLightbox={onOpenLightbox} />
                    </div>
                )}
            </div>
        </div>
    );
}

const INITIAL_VISIBLE = 50;

function ThreadView({ thread, currentActorType, currentActorId, currentActorRole, endpoints, onChange, onBack }) {
    const [body, setBody] = useState('');
    const [saving, setSaving] = useState(false);
    const [joining, setJoining] = useState(false);
    const [progress, setProgress] = useState(0);
    const [visibleCount, setVisibleCount] = useState(INITIAL_VISIBLE);
    const [lightbox, setLightbox] = useState(null); // { images, index }
    const attachments = usePendingAttachments();
    const bottomRef = useRef(null);

    const participants = threadParticipantActors(thread);
    const amParticipant = participants.some((p) => isSameActor(p, currentActorType, currentActorId));
    const allMessages = [thread, ...(thread.replies || [])];
    const hasEarlier = allMessages.length > visibleCount;
    const visibleMessages = allMessages.slice(Math.max(0, allMessages.length - visibleCount));

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ block: 'end' });
    }, [thread.id, allMessages.length]);

    function onKeyDown(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
            e.preventDefault();
            submitReply(e);
        }
    }

    function onPaste(e) {
        const files = Array.from(e.clipboardData?.items || [])
            .filter((item) => item.kind === 'file')
            .map((item) => item.getAsFile())
            .filter(Boolean);
        if (files.length) attachments.addFiles(files);
    }

    async function submitReply(e) {
        e.preventDefault();
        if (!body.trim() && attachments.files.length === 0) return;
        setSaving(true);
        setProgress(0);
        try {
            const form = new FormData();
            form.append('body', body);
            attachments.files.forEach(({ file }) => form.append('attachments[]', file));

            await api.postFormWithProgress(endpoints.reply(thread.id), form, setProgress);
            setBody('');
            attachments.clear();
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
                <div className="p-4">
                    {hasEarlier && (
                        <button
                            onClick={() => setVisibleCount((c) => c + INITIAL_VISIBLE)}
                            className="text-sm text-shadow-grey hover:text-gunmetal underline mb-4 block"
                        >
                            Load earlier messages
                        </button>
                    )}
                    {visibleMessages.map((message, i) => {
                        const previous = visibleMessages[i - 1];
                        const showSeparator = !previous || !isSameDay(previous.sent_at, message.sent_at);

                        return (
                            <div key={message.id}>
                                {showSeparator && (
                                    <div className="text-center text-xs text-shadow-grey font-medium my-4 first:mt-0">
                                        {formatDaySeparator(message.sent_at)}
                                    </div>
                                )}
                                <MessageRow
                                    message={message}
                                    isLast={i === visibleMessages.length - 1}
                                    endpoints={endpoints}
                                    currentActorType={currentActorType}
                                    currentActorId={currentActorId}
                                    currentActorRole={currentActorRole}
                                    onChange={onChange}
                                    onOpenLightbox={(images, index) => setLightbox({ images, index })}
                                />
                            </div>
                        );
                    })}
                    <div ref={bottomRef} />
                </div>
            </div>

            {amParticipant ? (
                <form
                    onSubmit={submitReply}
                    onDragOver={(e) => e.preventDefault()}
                    onDrop={(e) => { e.preventDefault(); if (e.dataTransfer.files?.length) attachments.addFiles(e.dataTransfer.files); }}
                    className="card card-padded"
                >
                    <AutoGrowTextarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        onKeyDown={onKeyDown}
                        onPaste={onPaste}
                        placeholder="Write a reply… (⌘/Ctrl+Enter to send)"
                    />
                    <PendingAttachments files={attachments.files} onRemove={attachments.removeFile} />
                    {saving && attachments.files.length > 0 && (
                        <div className="h-1 bg-border rounded mb-2 overflow-hidden">
                            <div className="h-full bg-fern transition-all" style={{ width: `${progress}%` }} />
                        </div>
                    )}
                    <div className="flex items-center justify-between">
                        <label className="icon-btn icon-btn-secondary cursor-pointer" title="Attach files">
                            <Paperclip size={20} />
                            <input type="file" multiple className="hidden" onChange={(e) => { attachments.addFiles(e.target.files); e.target.value = ''; }} />
                        </label>
                        <Button type="submit" disabled={saving}>Reply</Button>
                    </div>
                </form>
            ) : (
                <div className="card card-padded flex items-center justify-between">
                    <div className="text-sm text-shadow-grey">You're not part of this thread yet.</div>
                    <Button variant="confirm" onClick={join} disabled={joining}>Join thread</Button>
                </div>
            )}

            {lightbox && (
                <Lightbox
                    images={lightbox.images}
                    index={lightbox.index}
                    onClose={() => setLightbox(null)}
                    onNavigate={(index) => setLightbox({ ...lightbox, index })}
                />
            )}
        </div>
    );
}

export default function MessagesPanel({ project, currentActorType, currentActorId, currentActorRole, recipientOptions, endpoints, onChange }) {
    const [showForm, setShowForm] = useState(false);
    const [openThreadId, setOpenThreadId] = useState(null);

    const threads = project.messages || [];
    const openThread = threads.find((t) => t.id === openThreadId);

    if (openThread) {
        return (
            <ThreadView
                thread={openThread}
                currentActorType={currentActorType}
                currentActorId={currentActorId}
                currentActorRole={currentActorRole}
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
                <NewThreadForm
                    recipientOptions={recipientOptions}
                    endpoints={endpoints}
                    onCreate={() => { setShowForm(false); onChange(); }}
                    onCancel={() => setShowForm(false)}
                />
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
                                <div className="text-xs text-shadow-grey" title={formatDateTime(lastActivity)}>{formatDate(lastActivity)}</div>
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

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
            ? <a key={i} href={part} target="_blank" rel="noopener noreferrer" className="message__link">{part}</a>
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
        <div className="pending-attachments">
            {files.map(({ key, file, previewUrl }) => (
                <div key={key} className="pending-attachments__item">
                    {previewUrl ? (
                        <img src={previewUrl} alt={file.name} className="pending-attachments__thumb" />
                    ) : (
                        <div className="pending-attachments__file">
                            {file.name}
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={() => onRemove(key)}
                        className="pending-attachments__remove"
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
            className="input composer__textarea"
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
            className="card card--padded composer composer--new"
        >
            <div className="composer__label">To</div>
            <div className="composer__recipients">
                {recipientOptions.length === 0 && <div className="composer__empty">No one else is on this project yet.</div>}
                {recipientOptions.map((option) => (
                    <label
                        key={option.token}
                        className={`recipient-chip${selected.includes(option.token) ? ' recipient-chip--selected' : ''}`}
                    >
                        <input
                            type="checkbox"
                            hidden
                            checked={selected.includes(option.token)}
                            onChange={() => toggle(option.token)}
                        />
                        {option.name}
                        <span className="recipient-chip__sublabel">{option.sublabel}</span>
                    </label>
                ))}
            </div>
            <input
                placeholder="Subject"
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
                className="input composer__field"
            />
            <AutoGrowTextarea
                value={body}
                onChange={(e) => setBody(e.target.value)}
                onKeyDown={onKeyDown}
                onPaste={onPaste}
                placeholder="Message… (⌘/Ctrl+Enter to send)"
            />
            <PendingAttachments files={attachments.files} onRemove={attachments.removeFile} />
            {error && <div className="composer__error">{error}</div>}
            {saving && attachments.files.length > 0 && (
                <div className="progress composer__progress">
                    <div className="progress__bar" style={{ width: `${progress}%` }} />
                </div>
            )}
            <div className="composer__footer">
                <label className="icon-btn icon-btn--secondary composer__attach" title="Attach files">
                    <Paperclip size={20} />
                    <input type="file" multiple hidden onChange={(e) => { attachments.addFiles(e.target.files); e.target.value = ''; }} />
                </label>
                <div className="composer__actions">
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
                    className="message__image"
                >
                    <img
                        src={images[0].thumbnail_status === 'ready' ? endpoints.attachmentThumbnailUrl(images[0].id) : endpoints.attachmentUrl(images[0].id)}
                        alt={images[0].original_name}
                        className="message__image-img"
                    />
                </button>
            )}
            {images.length > 1 && (
                <div className="message__gallery">
                    {images.map((img, i) => (
                        <button
                            key={img.id}
                            type="button"
                            onClick={() => onOpenLightbox(lightboxImages, i)}
                            className="message__gallery-item"
                        >
                            <img
                                src={img.thumbnail_status === 'ready' ? endpoints.attachmentThumbnailUrl(img.id) : endpoints.attachmentUrl(img.id)}
                                alt={img.original_name}
                                className="message__gallery-img"
                            />
                        </button>
                    ))}
                </div>
            )}
            {files.length > 0 && (
                <div className="message__files">
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
        <div className="message">
            <div className="message__rail">
                <Avatar name={sender?.name} avatarUrl={sender?.avatar_url} id={sender?.id ?? sender?.name} responsive />
                {!isLast && <div className="message__connector" />}
            </div>
            <div className="message__main">
                <div className="message__header">
                    <span className="message__sender">{sender?.name || 'Unknown'}</span>
                    {isClientAuthor && <Badge tone="watermelon" label="Client" />}
                    <span className="message__time" title={formatDateTime(message.sent_at)}>
                        {formatRelativeTime(message.sent_at)}
                    </span>
                    {canModify && !editing && (
                        <span className="message__actions">
                            <button onClick={() => { setEditBody(message.body || ''); setEditing(true); }} className="icon-btn icon-btn--confirm" title="Edit">
                                <PencilSimple size={16} />
                            </button>
                            <button onClick={remove} className="icon-btn icon-btn--danger" title="Delete">
                                <Trash size={16} />
                            </button>
                        </span>
                    )}
                </div>

                {isDeleted ? (
                    <div className="message__card message__card--deleted">Message deleted</div>
                ) : editing ? (
                    <div className="message__card">
                        <textarea
                            value={editBody}
                            onChange={(e) => setEditBody(e.target.value)}
                            rows={3}
                            className="input message__edit-input"
                            autoFocus
                        />
                        <div className="message__edit-actions">
                            <Button variant="secondary" onClick={() => setEditing(false)}>Cancel</Button>
                            <Button onClick={saveEdit} disabled={saving}>Save</Button>
                        </div>
                    </div>
                ) : (
                    <div className="message__card">
                        {message.body && <div className="message__text">{linkify(message.body)}</div>}
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
            <button onClick={onBack} className="thread__back">&larr; All messages</button>

            <div className="card thread__card">
                <div className="thread__header">
                    <div className="thread__subject">{thread.subject}</div>
                    <div className="thread__participants">
                        With: {participants.map((p) => p.name).join(', ') || '—'}
                    </div>
                </div>
                <div className="thread__body">
                    {hasEarlier && (
                        <button
                            onClick={() => setVisibleCount((c) => c + INITIAL_VISIBLE)}
                            className="thread__load-earlier"
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
                                    <div className="thread__day">
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
                    className="card card--padded composer"
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
                        <div className="progress composer__progress">
                            <div className="progress__bar" style={{ width: `${progress}%` }} />
                        </div>
                    )}
                    <div className="composer__footer">
                        <label className="icon-btn icon-btn--secondary composer__attach" title="Attach files">
                            <Paperclip size={20} />
                            <input type="file" multiple hidden onChange={(e) => { attachments.addFiles(e.target.files); e.target.value = ''; }} />
                        </label>
                        <Button type="submit" disabled={saving}>Reply</Button>
                    </div>
                </form>
            ) : (
                <div className="card card--padded thread__join">
                    <div className="thread__join-text">You're not part of this thread yet.</div>
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
            <div className="messages-panel__toolbar">
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
                <div className="card thread-list">
                    {threads.map((thread) => {
                        const participants = threadParticipantActors(thread);
                        const amParticipant = participants.some((p) => isSameActor(p, currentActorType, currentActorId));
                        const lastActivity = thread.replies?.length ? thread.replies[thread.replies.length - 1].sent_at : thread.sent_at;

                        return (
                            <button
                                key={thread.id}
                                onClick={() => setOpenThreadId(thread.id)}
                                className="thread-list__item"
                            >
                                <div>
                                    <div className="thread-list__subject">
                                        {thread.subject}
                                        {!amParticipant && <Badge tone="watermelon" label="Not joined" />}
                                    </div>
                                    <div className="thread-list__participants">{participants.map((p) => p.name).join(', ') || '—'}</div>
                                </div>
                                <div className="thread-list__date" title={formatDateTime(lastActivity)}>{formatDate(lastActivity)}</div>
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

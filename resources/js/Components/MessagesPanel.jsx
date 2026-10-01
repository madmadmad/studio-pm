import { useEffect, useRef, useState } from 'react';
import { LinkSimple, Paperclip, PencilSimple, Trash, X } from '@phosphor-icons/react';
import EmojiPicker, { REACTION_EMOJI } from './EmojiPicker';
import { formatDate, formatDateTime, formatDaySeparator, formatRelativeTime, isSameDay } from '../lib/format';
import { api } from '../lib/api';
import Badge from './Badge';
import Button from './Button';
import EmptyState from './EmptyState';
import Avatar from './Avatar';
import AttachmentChip, { iconFor } from './AttachmentChip';
import LinkChip, { describeLink } from './LinkChip';
import Lightbox from './Lightbox';
import { DrawerByline, DrawerDate } from './Drawer';

function participantName(participant) {
    return participant.user?.name ?? participant.contact?.name ?? 'Unknown';
}

export function isSameActor(a, currentActorType, currentActorId) {
    return a.type === currentActorType && String(a.id) === String(currentActorId);
}

export function threadParticipantActors(thread) {
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

// A message's reactions as chips: one per emoji (in the picker's order),
// with its count, who reacted, and whether you did.
function groupReactions(reactions, currentActorType, currentActorId) {
    const byEmoji = new Map();
    for (const reaction of reactions) {
        const entry = byEmoji.get(reaction.emoji) ?? { emoji: reaction.emoji, count: 0, names: [], mine: false };
        entry.count += 1;
        entry.names.push(reaction.user?.name ?? reaction.contact?.name ?? 'Someone');
        const reactorId = currentActorType === 'contact' ? reaction.contact_id : reaction.user_id;
        if (reactorId != null && String(reactorId) === String(currentActorId)) entry.mine = true;
        byEmoji.set(reaction.emoji, entry);
    }
    return [...byEmoji.values()].sort((a, b) => REACTION_EMOJI.indexOf(a.emoji) - REACTION_EMOJI.indexOf(b.emoji));
}

// Only a message's author can edit or delete it (MessagePolicy), whatever
// their role.
function canModifyMessage(message, currentActorType, currentActorId) {
    if (message.deleted_at) return false;

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

// A non-image file's or a link's tile: its glyph over its name.
function PendingTile({ Icon, name }) {
    return (
        <div className="pending-attachments__file" title={name}>
            <Icon size={20} className="pending-attachments__icon" />
            <span className="pending-attachments__name">{name}</span>
        </div>
    );
}

// Files -- and links (the link button) -- waiting to be sent, as tiles in
// one row: a file's thumbnail or name, a link's service glyph and name,
// each with the same remove button on its corner.
function PendingAttachments({ files, onRemove, links = [], onRemoveLink }) {
    if (files.length === 0 && links.length === 0) return null;

    return (
        <div className="pending-attachments">
            {files.map(({ key, file, previewUrl }) => (
                <div key={key} className="pending-attachments__item">
                    {previewUrl ? (
                        <img src={previewUrl} alt={file.name} className="pending-attachments__thumb" />
                    ) : (
                        <PendingTile Icon={iconFor(file.name)} name={file.name} />
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
            {links.map((url) => {
                const { title, Icon } = describeLink(url);
                return (
                    <div key={url} className="pending-attachments__item" title={url}>
                        <PendingTile Icon={Icon} name={title} />
                        <button
                            type="button"
                            onClick={() => onRemoveLink(url)}
                            title="Remove link"
                            aria-label="Remove link"
                            className="pending-attachments__remove"
                        >
                            <X size={12} weight="bold" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}

// A pasted link made whole: "dropbox.com/s/..." gets its https://, and
// anything that isn't a web address comes back null.
function normalizeLink(raw) {
    let value = raw.trim();
    if (!value) return null;
    if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(value)) value = `https://${value}`;
    try {
        const url = new URL(value);
        return ['http:', 'https:'].includes(url.protocol) && url.hostname.includes('.') ? url.toString() : null;
    } catch {
        return null;
    }
}

// Links waiting in a composer (the link button), sent with the message.
function usePendingLinks() {
    const [links, setLinks] = useState([]);
    return {
        links,
        add: (url) => setLinks((current) => (current.includes(url) ? current : [...current, url])),
        remove: (url) => setLinks((current) => current.filter((l) => l !== url)),
        clear: () => setLinks([]),
    };
}

// The composer's link button and what it opens: a field to paste a link
// into (Enter or "Add link" adds it, Escape closes it). Returns { button,
// panel } for the composer to place; added links wait as tiles beside any
// files (PendingAttachments).
function useLinkTool(pending) {
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState('');
    const [error, setError] = useState('');

    function add() {
        const url = normalizeLink(value);
        if (!url) {
            setError('That doesn’t look like a link.');
            return;
        }
        pending.add(url);
        setValue('');
        setError('');
        setOpen(false);
    }

    return {
        button: (
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                title="Share a link"
                aria-label="Share a link"
                aria-expanded={open}
                className="icon-btn icon-btn--secondary composer__attach"
            >
                <LinkSimple size={20} />
            </button>
        ),
        panel: (
            <>
                {open && (
                    <div className="composer__link">
                        <input
                            autoFocus
                            type="url"
                            inputMode="url"
                            placeholder="Paste a link — Dropbox, Google Drive, WeTransfer…"
                            value={value}
                            onChange={(e) => {
                                setValue(e.target.value);
                                setError('');
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    add();
                                }
                                if (e.key === 'Escape') {
                                    e.stopPropagation();
                                    setOpen(false);
                                }
                            }}
                            aria-label="Link"
                            className="input composer__link-input"
                        />
                        <Button type="button" variant="secondary" onClick={add}>Add link</Button>
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>Cancel</Button>
                    </div>
                )}
                {error && open && <div className="composer__error">{error}</div>}
            </>
        ),
    };
}

// Types an emoji into a composer at the cursor (or over a selection), then
// puts the cursor back just after it. `ref` goes on the textarea.
function useEmojiInsert(value, setValue) {
    const ref = useRef(null);

    function insert(emoji) {
        const el = ref.current;
        const start = el?.selectionStart ?? value.length;
        const end = el?.selectionEnd ?? value.length;
        setValue(value.slice(0, start) + emoji + value.slice(end));
        requestAnimationFrame(() => {
            if (!el) return;
            el.focus();
            el.setSelectionRange(start + emoji.length, start + emoji.length);
        });
    }

    return { ref, insert };
}

function AutoGrowTextarea({ value, onChange, onKeyDown, onPaste, placeholder, autoFocus, textareaRef }) {
    const ref = useRef(null);

    useEffect(() => {
        if (!ref.current) return;
        ref.current.style.height = 'auto';
        ref.current.style.height = `${ref.current.scrollHeight}px`;
    }, [value]);

    return (
        <textarea
            ref={(el) => {
                ref.current = el;
                if (textareaRef) textareaRef.current = el;
            }}
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

// `bare` drops the card framing, for use inside a drawer.
export function NewThreadForm({ recipientOptions, endpoints, onCreate, onCancel, bare = false }) {
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const emoji = useEmojiInsert(body, setBody);
    const [selected, setSelected] = useState([]);
    const [saving, setSaving] = useState(false);
    const [progress, setProgress] = useState(0);
    const [error, setError] = useState('');
    const attachments = usePendingAttachments();
    const links = usePendingLinks();
    const linkTool = useLinkTool(links);

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
        if (!subject.trim() || (!body.trim() && attachments.files.length === 0 && links.links.length === 0) || selected.length === 0) {
            setError('Add a subject, a recipient, and a message, file or link.');
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
            links.links.forEach((url) => form.append('links[]', url));

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
            className={`${bare ? '' : 'card card--padded '}composer composer--new`}
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
                textareaRef={emoji.ref}
            />
            <PendingAttachments files={attachments.files} onRemove={attachments.removeFile} links={links.links} onRemoveLink={links.remove} />
            {linkTool.panel}
            {error && <div className="composer__error">{error}</div>}
            {saving && attachments.files.length > 0 && (
                <div className="progress composer__progress">
                    <div className="progress__bar" style={{ width: `${progress}%` }} />
                </div>
            )}
            <div className="composer__footer">
                <div className="composer__tools">
                    <label className="icon-btn icon-btn--secondary composer__attach" title="Attach files">
                        <Paperclip size={20} />
                        <input type="file" multiple hidden onChange={(e) => { attachments.addFiles(e.target.files); e.target.value = ''; }} />
                    </label>
                    {linkTool.button}
                    <EmojiPicker onPick={emoji.insert} placement="up" />
                </div>
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
    const links = message.links || [];
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
            {(files.length > 0 || links.length > 0) && (
                <div className="message__files">
                    {files.map((file) => (
                        <AttachmentChip key={file.id} attachment={file} downloadUrl={endpoints.attachmentUrl(file.id)} />
                    ))}
                    {links.map((link) => <LinkChip key={`link-${link.id}`} url={link.url} />)}
                </div>
            )}
        </>
    );
}

function MessageRow({ message, endpoints, currentActorType, currentActorId, onChange, onOpenLightbox }) {
    const [editing, setEditing] = useState(false);
    const [editBody, setEditBody] = useState(message.body || '');
    const [saving, setSaving] = useState(false);
    const [editError, setEditError] = useState('');
    // An edit can take files and links off (kept by id until Save) and
    // add new ones, as the composer does.
    const [removedFileIds, setRemovedFileIds] = useState([]);
    const [removedLinkIds, setRemovedLinkIds] = useState([]);
    const newFiles = usePendingAttachments();
    const newLinks = usePendingLinks();
    const linkTool = useLinkTool(newLinks);
    const editEmoji = useEmojiInsert(editBody, setEditBody);

    const sender = message.sender_user || message.sender_contact;
    const isClientAuthor = !!message.sender_contact;
    const isDeleted = !!message.deleted_at;
    const canModify = canModifyMessage(message, currentActorType, currentActorId);
    const reactions = groupReactions(message.reactions || [], currentActorType, currentActorId);

    async function toggleReaction(emoji) {
        await api.post(endpoints.react(message.id), { emoji });
        onChange();
    }

    function startEditing() {
        setEditBody(message.body || '');
        setRemovedFileIds([]);
        setRemovedLinkIds([]);
        newFiles.clear();
        newLinks.clear();
        setEditError('');
        setEditing(true);
    }

    // What the edit keeps of the message's own files and links.
    const keptFiles = (message.attachments || []).filter((a) => !removedFileIds.includes(a.id));
    const keptLinks = (message.links || []).filter((l) => !removedLinkIds.includes(l.id));
    // As tiles beside the new ones: kept files take the composer's file
    // shape (a thumbnail for an image), keyed so a remove can tell them apart.
    const editTiles = [
        ...keptFiles.map((a) => ({
            key: `saved-${a.id}`,
            file: { name: a.original_name },
            previewUrl: a.is_image ? (a.thumbnail_status === 'ready' ? endpoints.attachmentThumbnailUrl(a.id) : endpoints.attachmentUrl(a.id)) : null,
        })),
        ...newFiles.files,
    ];
    const editLinkUrls = [...keptLinks.map((l) => l.url), ...newLinks.links];

    function removeEditTile(key) {
        if (key.startsWith('saved-')) setRemovedFileIds((ids) => [...ids, Number(key.slice(6))]);
        else newFiles.removeFile(key);
    }

    function removeEditLink(url) {
        const saved = keptLinks.find((l) => l.url === url);
        if (saved) setRemovedLinkIds((ids) => [...ids, saved.id]);
        else newLinks.remove(url);
    }

    async function saveEdit() {
        if (!editBody.trim() && editTiles.length === 0 && editLinkUrls.length === 0) {
            setEditError('A message needs text, a file or a link.');
            return;
        }
        setSaving(true);
        setEditError('');
        try {
            const form = new FormData();
            form.append('_method', 'PATCH');
            form.append('body', editBody);
            newFiles.files.forEach(({ file }) => form.append('attachments[]', file));
            newLinks.links.forEach((url) => form.append('links[]', url));
            removedFileIds.forEach((id) => form.append('remove_attachment_ids[]', id));
            removedLinkIds.forEach((id) => form.append('remove_link_ids[]', id));
            await api.postForm(endpoints.update(message.id), form);
            setEditing(false);
            onChange();
        } catch (err) {
            setEditError(err.message || 'Could not save this message.');
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
                <div className="message__rule" />
            </div>
            <div className="message__main">
                <div className="message__header">
                    <span className="message__sender">{sender?.name || 'Unknown'}</span>
                    {isClientAuthor && <Badge tone="accent" label="Client" />}
                    <span className="message__time" title={formatDateTime(message.sent_at)}>
                        {formatRelativeTime(message.sent_at)}
                    </span>
                    {!isDeleted && !editing && (
                        <span className="message__actions">
                            {endpoints.react && (
                                <span className="message__react">
                                    <EmojiPicker emojis={REACTION_EMOJI} onPick={toggleReaction} label="Add reaction" size={16} align="right" />
                                </span>
                            )}
                            {canModify && (
                                <>
                                    <button onClick={startEditing} className="icon-btn icon-btn--edit" title="Edit">
                                        <PencilSimple size={16} />
                                    </button>
                                    <button onClick={remove} className="icon-btn icon-btn--danger" title="Delete">
                                        <Trash size={16} />
                                    </button>
                                </>
                            )}
                        </span>
                    )}
                </div>

                {isDeleted ? (
                    <div className="message__body message__body--deleted">Message deleted</div>
                ) : editing ? (
                    <div
                        className="message__body message__edit"
                        onDragOver={(e) => e.preventDefault()}
                        onDrop={(e) => { e.preventDefault(); if (e.dataTransfer.files?.length) newFiles.addFiles(e.dataTransfer.files); }}
                    >
                        <AutoGrowTextarea
                            value={editBody}
                            onChange={(e) => setEditBody(e.target.value)}
                            placeholder="Message…"
                            autoFocus
                            textareaRef={editEmoji.ref}
                        />
                        <PendingAttachments files={editTiles} onRemove={removeEditTile} links={editLinkUrls} onRemoveLink={removeEditLink} />
                        {linkTool.panel}
                        {editError && <div className="composer__error">{editError}</div>}
                        <div className="composer__footer">
                            <div className="composer__tools">
                                <label className="icon-btn icon-btn--secondary composer__attach" title="Attach files">
                                    <Paperclip size={20} />
                                    <input type="file" multiple hidden onChange={(e) => { newFiles.addFiles(e.target.files); e.target.value = ''; }} />
                                </label>
                                {linkTool.button}
                                <EmojiPicker onPick={editEmoji.insert} placement="up" />
                            </div>
                            <div className="composer__actions">
                                <Button variant="secondary" onClick={() => setEditing(false)}>Cancel</Button>
                                <Button onClick={saveEdit} disabled={saving}>Save</Button>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="message__body">
                        {message.body && <div className="message__text">{linkify(message.body)}</div>}
                        <MessageAttachments message={message} endpoints={endpoints} onOpenLightbox={onOpenLightbox} />
                    </div>
                )}

                {/* Reactions: one chip per emoji with its count; yours are
                    picked out, and clicking a chip adds or removes yours. */}
                {!isDeleted && reactions.length > 0 && (
                    <div className="message__reactions">
                        {reactions.map((reaction) => (
                            <button
                                key={reaction.emoji}
                                type="button"
                                onClick={() => endpoints.react && toggleReaction(reaction.emoji)}
                                disabled={!endpoints.react}
                                title={reaction.names.join(', ')}
                                aria-pressed={reaction.mine}
                                className={`message__reaction${reaction.mine ? ' message__reaction--mine' : ''}`}
                            >
                                <span className="message__reaction-emoji">{reaction.emoji}</span>
                                <span className="message__reaction-count">{reaction.count}</span>
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

const INITIAL_VISIBLE = 50;

// `bare` drops the card framing (thread, reply box, join prompt), for use
// inside a drawer; the drawer is the frame.
export function ThreadView({ thread, currentActorType, currentActorId, endpoints, onChange, onBack, bare = false }) {
    const [body, setBody] = useState('');
    const emoji = useEmojiInsert(body, setBody);
    const [saving, setSaving] = useState(false);
    const [joining, setJoining] = useState(false);
    const [progress, setProgress] = useState(0);
    const [visibleCount, setVisibleCount] = useState(INITIAL_VISIBLE);
    const [lightbox, setLightbox] = useState(null); // { images, index }
    const attachments = usePendingAttachments();
    const links = usePendingLinks();
    const linkTool = useLinkTool(links);
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
        if (!body.trim() && attachments.files.length === 0 && links.links.length === 0) return;
        setSaving(true);
        setProgress(0);
        try {
            const form = new FormData();
            form.append('body', body);
            attachments.files.forEach(({ file }) => form.append('attachments[]', file));
            links.links.forEach((url) => form.append('links[]', url));

            await api.postFormWithProgress(endpoints.reply(thread.id), form, setProgress);
            setBody('');
            attachments.clear();
            links.clear();
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

    const card = bare ? '' : 'card ';
    const paddedCard = bare ? '' : 'card card--padded ';

    return (
        <div className={bare ? 'thread thread--bare' : 'thread'}>
            {onBack && <button onClick={onBack} className="thread__back">&larr; All messages</button>}

            <div className={`${card}thread__card`}>
                {bare ? (
                    // In a drawer: the standard drawer opening -- byline
                    // (started date, participants), then the subject.
                    <>
                        <DrawerByline>
                            <DrawerDate label="Started" date={thread.sent_at} />
                            <span>With {participants.map((p) => p.name).join(', ') || '—'}</span>
                        </DrawerByline>
                        <h2 className="drawer__title">{thread.subject}</h2>
                    </>
                ) : (
                    <div className="thread__header">
                        <div className="thread__subject">{thread.subject}</div>
                        <div className="thread__participants">
                            With: {participants.map((p) => p.name).join(', ') || '—'}
                        </div>
                    </div>
                )}
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
                                    endpoints={endpoints}
                                    currentActorType={currentActorType}
                                    currentActorId={currentActorId}
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
                    className={`${paddedCard}composer`}
                >
                    <AutoGrowTextarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        onKeyDown={onKeyDown}
                        onPaste={onPaste}
                        placeholder="Write a reply… (⌘/Ctrl+Enter to send)"
                        textareaRef={emoji.ref}
                    />
                    <PendingAttachments files={attachments.files} onRemove={attachments.removeFile} links={links.links} onRemoveLink={links.remove} />
                    {linkTool.panel}
                    {saving && attachments.files.length > 0 && (
                        <div className="progress composer__progress">
                            <div className="progress__bar" style={{ width: `${progress}%` }} />
                        </div>
                    )}
                    <div className="composer__footer">
                        <div className="composer__tools">
                            <label className="icon-btn icon-btn--secondary composer__attach" title="Attach files">
                                <Paperclip size={20} />
                                <input type="file" multiple hidden onChange={(e) => { attachments.addFiles(e.target.files); e.target.value = ''; }} />
                            </label>
                            {linkTool.button}
                            <EmojiPicker onPick={emoji.insert} placement="up" />
                        </div>
                        <Button type="submit" disabled={saving}>Reply</Button>
                    </div>
                </form>
            ) : (
                <div className={`${paddedCard}thread__join`}>
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

export default function MessagesPanel({ project, currentActorType, currentActorId, recipientOptions, endpoints, onChange }) {
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
                                        {!amParticipant && <Badge tone="accent" label="Not joined" />}
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
